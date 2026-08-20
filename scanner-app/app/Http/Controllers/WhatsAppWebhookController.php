<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppImageJob;
use App\Models\ApiKey;
use App\Models\Pricelist;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessageLog;
use App\Models\WhatsAppSetting;
use App\Services\WhatsApp\WhatsAppServiceInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Webhook Controller
 *
 * Handles incoming WhatsApp messages and routes them to appropriate handlers:
 * - IMAGE → Download → ProcessWhatsAppImageJob → Reply with scan results
 * - TEXT "help" → Reply with help menu
 * - TEXT "status" → Reply with last scan status
 * - TEXT "hasil" → Reply with last scan results
 * - TEXT other → Forward to AI Chat (Gemini) for data analysis
 */
class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private WhatsAppServiceInterface $whatsapp
    ) {}

    /**
     * Webhook verification (GET request).
     * Used by Meta Cloud API for challenge/response handshake.
     */
    public function verify(Request $request)
    {
        return $this->whatsapp->verifyWebhook($request);
    }

    /**
     * Handle incoming webhook (POST request).
     * Must return 200 immediately — all processing is async via Jobs.
     */
    public function handle(Request $request)
    {
        try {
            $message = $this->whatsapp->parseIncomingMessage($request);

            if (!$message) {
                return response('EVENT_RECEIVED', 200);
            }

            // Get or create conversation
            $conversation = WhatsAppConversation::findOrCreateByPhone(
                $message->from,
                $message->senderName
            );

            // Log incoming message
            WhatsAppMessageLog::logIncoming(
                $conversation->id,
                $message->type,
                $message->getEffectiveText() ?: "[{$message->type}]",
                null,
                $message->messageId
            );

            // Route message by type
            if ($message->isImage()) {
                $this->handleImage($conversation, $message);
            } elseif ($message->isDocument()) {
                $this->handleDocument($conversation, $message);
            } else {
                $this->handleText($conversation, $message);
            }

        } catch (\Exception $e) {
            Log::error("WhatsApp webhook error: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Always return 200 to prevent Meta/Evolution from retrying
        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Handle incoming image or file message.
     */
    private function handleImage(WhatsAppConversation $conversation, $message): void
    {
        try {
            // Download media
            $mediaPath = $this->whatsapp->downloadMedia($message->mediaId);
            $filename = $message->mediaFilename ?? basename($mediaPath);

            // Update the incoming message with media path
            $lastMsg = WhatsAppMessageLog::where('conversation_id', $conversation->id)
                ->where('wa_message_id', $message->messageId)
                ->first();
            if ($lastMsg) {
                $lastMsg->update(['media_path' => $mediaPath]);
            }

            // Check if there is an existing active pricelist session
            if ($conversation->pricelist_id && Pricelist::where('id', $conversation->pricelist_id)->exists()) {
                // Save to context and ask user whether to create a New Session or Append
                $conversation->update([
                    'context' => [
                        'pending_media' => $mediaPath,
                        'pending_filename' => $filename,
                        'pending_caption' => $message->caption,
                    ]
                ]);

                $promptMsg = "📁 *File Diterima!*\n"
                    . "📄 File: `{$filename}`\n\n"
                    . "Pilih mode pengolahan dataset:\n"
                    . "1️⃣ *Sesi Baru* (Buat Sesi Chat & Dataset Baru)\n"
                    . "2️⃣ *Gabung Sesi* (Tambahkan ke Dataset Sebelumnya)\n\n"
                    . "_Balas *1* (Sesi Baru) atau *2* (Gabung)._";

                $this->whatsapp->sendTextMessage($conversation->phone_number, $promptMsg);
                WhatsAppMessageLog::logOutgoing($conversation->id, $promptMsg);
                return;
            }

            // If no active session, automatically start a New Session
            $this->processMediaJob($conversation, $mediaPath, $message->caption, false, $filename);

        } catch (\Exception $e) {
            Log::error("WhatsApp image/document handling failed: " . $e->getMessage());
            $this->whatsapp->sendTextMessage(
                $conversation->phone_number,
                "❌ Gagal mengunduh file: " . $e->getMessage() . ". Silakan coba kirim ulang."
            );
        }
    }

    /**
     * Helper to dispatch media processing job and reply immediately.
     */
    private function processMediaJob(WhatsAppConversation $conversation, string $mediaPath, ?string $caption, bool $isAppend, string $filename): void
    {
        $modeText = $isAppend ? "➕ *Menggabungkan ke Dataset Sebelumnya...*" : "🚀 *Mulai Sesi Scan Baru...*";
        $reply = "{$modeText}\n📄 File: `{$filename}`\n⏳ Mohon tunggu sebentar, Gemini AI sedang mengekstrak data...";

        $this->whatsapp->sendTextMessage($conversation->phone_number, $reply);
        WhatsAppMessageLog::logOutgoing($conversation->id, $reply);

        ProcessWhatsAppImageJob::dispatch(
            $conversation->id,
            $mediaPath,
            $caption,
            $isAppend
        );
    }

    /**
     * Handle incoming document (ZIP, CSV, XLSX, Images).
     */
    private function handleDocument(WhatsAppConversation $conversation, $message): void
    {
        $filename = strtolower($message->mediaFilename ?? '');
        $mime = strtolower($message->mediaMimeType ?? '');

        $isZip = str_ends_with($filename, '.zip') || str_contains($mime, 'zip') || str_contains($mime, 'compressed') || str_contains($mime, 'octet-stream');
        $isExcel = str_ends_with($filename, '.xlsx') || str_ends_with($filename, '.xls') || str_contains($mime, 'spreadsheet') || str_contains($mime, 'excel');
        $isCsv = str_ends_with($filename, '.csv') || str_contains($mime, 'csv');
        $isImage = str_contains($mime, 'image');

        if (!$isZip && !$isExcel && !$isCsv && !$isImage) {
            $this->whatsapp->sendTextMessage(
                $conversation->phone_number,
                "⚠️ Format file `{$message->mediaFilename}` tidak didukung.\nSilakan kirim Gambar (JPG/PNG) atau file ZIP berisi gambar pricelist."
            );
            return;
        }

        // Process file (ZIP or Image)
        $this->handleImage($conversation, $message);
    }

    /**
     * Handle incoming text message.
     * Routes to pending file choices, commands, or AI chat.
     */
    private function handleText(WhatsAppConversation $conversation, $message): void
    {
        $text = strtolower(trim($message->text));
        $context = $conversation->context ?? [];

        // 1. Check if user is replying to a pending file mode selection (1 = Sesi Baru, 2 = Gabung)
        if (!empty($context['pending_media'])) {
            $mediaPath = $context['pending_media'];
            $filename = $context['pending_filename'] ?? basename($mediaPath);
            $caption = $context['pending_caption'] ?? null;

            // Clear pending context
            $conversation->update(['context' => null]);

            if (in_array($text, ['2', 'gabung', 'append', '2️⃣'])) {
                $this->processMediaJob($conversation, $mediaPath, $caption, true, $filename);
            } else {
                // Default or choice '1' = Sesi Baru
                $this->processMediaJob($conversation, $mediaPath, $caption, false, $filename);
            }
            return;
        }

        // 2. Command routing
        match (true) {
            in_array($text, ['help', 'bantuan', 'menu', 'start']) => $this->replyHelp($conversation),
            in_array($text, ['status', 'cek', 'cek status']) => $this->replyStatus($conversation),
            in_array($text, ['1', 'hasil', 'result', 'hasil scan']) => $this->replyLastResults($conversation),
            in_array($text, ['2', 'insight', 'insights', 'analisis']) => $this->replyInsights($conversation),
            in_array($text, ['3', 'export', 'rekap']) => $this->replyExport($conversation),
            in_array($text, ['4', 'baru', 'new', 'sesi baru']) => $this->resetSession($conversation),
            in_array($text, ['hi', 'halo', 'hello', 'hai']) => $this->replyGreeting($conversation),
            default => $this->handleAiChat($conversation, $message->text),
        };
    }

    /**
     * Start a fresh new session.
     */
    private function resetSession(WhatsAppConversation $conversation): void
    {
        $conversation->update(['pricelist_id' => null, 'context' => null]);
        $msg = "🆕 *Sesi Scan Baru Dibuat!*\n\nSilakan kirim Gambar atau file ZIP pricelist baru untuk memulai scan.";
        $this->whatsapp->sendTextMessage($conversation->phone_number, $msg);
        WhatsAppMessageLog::logOutgoing($conversation->id, $msg);
    }

    /**
     * Helper to get a valid pricelist with packages for conversation.
     */
    private function getValidPricelist(WhatsAppConversation $conversation): ?Pricelist
    {
        if ($conversation->pricelist_id) {
            $candidate = Pricelist::find($conversation->pricelist_id);
            if ($candidate && $candidate->packages()->count() > 0) {
                return $candidate;
            }
        }

        return Pricelist::where('status', 'processed')
            ->whereHas('packages')
            ->latest()
            ->first();
    }

    /**
     * Reply with AI Insights.
     */
    private function replyInsights(WhatsAppConversation $conversation): void
    {
        $pricelist = $this->getValidPricelist($conversation);
        if (!$pricelist || $pricelist->packages()->count() === 0) {
            $this->whatsapp->sendTextMessage($conversation->phone_number, "ℹ️ Belum ada data untuk dianalisis. Kirim gambar/ZIP pricelist terlebih dahulu!");
            return;
        }

        try {
            $payload = $pricelist->packages()->where('category', '!=', 'REJECTED')->get()->map(fn($p) => [
                'provider' => $p->provider,
                'package_name' => $p->package_name,
                'price' => (int) $p->price,
                'gb' => (float) $p->gb,
                'days' => (int) $p->days,
                'yield_val' => (float) $p->yield_val,
                'category' => $p->category,
            ])->toArray();

            $response = Http::timeout(60)->post(env('FASTAPI_URL', 'http://127.0.0.1:8091') . '/api/insights', [
                'packages' => $payload
            ]);

            if ($response->successful()) {
                $rawInsights = $response->json('data');
                $insightsText = is_array($rawInsights) ? json_encode($rawInsights, JSON_PRETTY_PRINT) : (string) $rawInsights;

                $text = "📈 *AI Market Insights & Benchmarking*\n"
                    . "📄 Sesi: {$pricelist->filename}\n"
                    . "━━━━━━━━━━━━━━━━━━\n\n"
                    . $insightsText;

                if (strlen($text) > 4000) {
                    $text = substr($text, 0, 3950) . "\n\n_... (dipotong)_";
                }

                $this->whatsapp->sendTextMessage($conversation->phone_number, $text);
                WhatsAppMessageLog::logOutgoing($conversation->id, $text);
            } else {
                throw new \RuntimeException("FastAPI insights error: " . $response->body());
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp Insights error: " . $e->getMessage());
            $this->whatsapp->sendTextMessage($conversation->phone_number, "⚠️ Gagal mengambil AI Insights: " . $e->getMessage());
        }
    }

    /**
     * Reply with text export summary.
     */
    private function replyExport(WhatsAppConversation $conversation): void
    {
        $pricelist = $this->getValidPricelist($conversation);
        if (!$pricelist || $pricelist->packages()->count() === 0) {
            $this->whatsapp->sendTextMessage($conversation->phone_number, "ℹ️ Belum ada data untuk diekspor.");
            return;
        }

        $pkgs = $pricelist->packages()->where('category', '!=', 'REJECTED')->get();
        $lines = ["📥 *Rekap Data Pricelist*", "📄 {$pricelist->filename}", "━━━━━━━━━━━━━━━━━━", ""];

        foreach ($pkgs as $p) {
            $price = number_format($p->price, 0, ',', '.');
            $lines[] = "• [{$p->provider}] {$p->package_name}: {$p->gb}GB / {$p->days}hr = Rp {$price} (Yield: {$p->yield_val})";
        }

        $exportText = implode("\n", $lines);
        if (strlen($exportText) > 4000) {
            $exportText = substr($exportText, 0, 3950) . "\n\n_... (data dipotong)_";
        }

        $this->whatsapp->sendTextMessage($conversation->phone_number, $exportText);
        WhatsAppMessageLog::logOutgoing($conversation->id, $exportText);
    }

    /**
     * Reply with help menu.
     */
    private function replyHelp(WhatsAppConversation $conversation): void
    {
        $helpText = "📋 *Pricelist Scanner Bot (VIPER)*\n"
            . "━━━━━━━━━━━━━━━━━━\n\n"
            . "🖼️ *Kirim Gambar* → Scan & ekstrak data pricelist\n"
            . "📁 *Kirim ZIP* → Scan batch gambar pricelist\n\n"
            . "💡 *Pilih Menu & Perintah:*\n"
            . "1️⃣ *hasil* — Lihat Detail Paket Hasil Scan\n"
            . "2️⃣ *insight* — Analisis AI Insights & Benchmarking\n"
            . "3️⃣ *export* — Lihat Ringkasan Rekap Data\n"
            . "4️⃣ *baru* — Mulai Sesi Scan Baru\n"
            . "• *status* — Cek Status Scan Terakhir\n"
            . "• *help* — Tampilkan Menu Bantuan Ini\n\n"
            . "💬 *Tanya Jawab AI:*\n"
            . "Kirim pertanyaan apapun tentang data yang di-scan, contoh:\n"
            . "• _\"Mana paket yang paling murah?\"_\n"
            . "• _\"Bandingkan Telkomsel dan XL\"_\n"
            . "• _\"Rekomendasikan paket sachet termurah\"_\n\n"
            . "🤖 Powered by Gemini AI";

        $this->whatsapp->sendTextMessage($conversation->phone_number, $helpText);
        WhatsAppMessageLog::logOutgoing($conversation->id, $helpText);
    }

    /**
     * Reply with greeting.
     */
    private function replyGreeting(WhatsAppConversation $conversation): void
    {
        $name = $conversation->sender_name ?? 'Kak';
        $greeting = "👋 Halo {$name}! Selamat datang di *Pricelist Scanner Bot (VIPER)*.\n\n"
            . "Kirim Gambar/ZIP pricelist untuk mulai scan, atau ketik *help* untuk melihat menu.";

        $this->whatsapp->sendTextMessage($conversation->phone_number, $greeting);
        WhatsAppMessageLog::logOutgoing($conversation->id, $greeting);
    }

    /**
     * Reply with last scan status.
     */
    private function replyStatus(WhatsAppConversation $conversation): void
    {
        $pricelist = Pricelist::where('id', $conversation->pricelist_id)->first();

        if (!$pricelist) {
            // Try finding latest pricelist that was created from WhatsApp
            $pricelist = Pricelist::where('filename', 'like', 'WA:%')
                ->latest()
                ->first();
        }

        if (!$pricelist) {
            $this->whatsapp->sendTextMessage(
                $conversation->phone_number,
                "ℹ️ Belum ada scan. Kirim gambar pricelist untuk memulai!"
            );
            return;
        }

        $statusEmoji = match ($pricelist->status) {
            'processed' => '✅',
            'processing' => '⏳',
            'pending' => '🕐',
            'failed' => '❌',
            'cancelled' => '🚫',
            default => '📋',
        };

        $statusText = "{$statusEmoji} *Status Scan Terakhir*\n"
            . "━━━━━━━━━━━━━━━━━━\n"
            . "📄 File: {$pricelist->filename}\n"
            . "📊 Status: {$pricelist->status}\n"
            . "📦 Paket: {$pricelist->packages()->count()}\n"
            . "🕐 Waktu: {$pricelist->updated_at->diffForHumans()}";

        if ($pricelist->error_message) {
            $statusText .= "\n⚠️ Error: " . substr($pricelist->error_message, 0, 200);
        }

        $this->whatsapp->sendTextMessage($conversation->phone_number, $statusText);
        WhatsAppMessageLog::logOutgoing($conversation->id, $statusText);
    }

    /**
     * Reply with last scan results.
     */
    private function replyLastResults(WhatsAppConversation $conversation): void
    {
        $pricelist = $this->getValidPricelist($conversation);

        if (!$pricelist) {
            $this->whatsapp->sendTextMessage(
                $conversation->phone_number,
                "ℹ️ Belum ada hasil scan yang tersedia. Kirim gambar pricelist terlebih dahulu!"
            );
            return;
        }

        $packages = $pricelist->packages()->where('category', '!=', 'REJECTED')->get();

        if ($packages->isEmpty()) {
            $this->whatsapp->sendTextMessage(
                $conversation->phone_number,
                "⚠️ Scan terakhir tidak menghasilkan data paket."
            );
            return;
        }

        // Build detailed results
        $lines = [
            "📊 *Detail Hasil Scan*",
            "📄 {$pricelist->filename}",
            "━━━━━━━━━━━━━━━━━━",
            "",
        ];

        $grouped = $packages->groupBy('provider');
        foreach ($grouped as $provider => $pkgs) {
            $lines[] = "🏢 *{$provider}*";
            foreach ($pkgs->take(5) as $pkg) {
                $price = number_format($pkg->price, 0, ',', '.');
                $lines[] = "  • {$pkg->package_name}: {$pkg->gb}GB/{$pkg->days}hr = Rp {$price}";
            }
            if ($pkgs->count() > 5) {
                $remaining = $pkgs->count() - 5;
                $lines[] = "  _... +{$remaining} paket lainnya_";
            }
            $lines[] = "";
        }

        $lines[] = "💬 Tanya apapun tentang data ini!";

        $resultText = implode("\n", $lines);
        $this->whatsapp->sendTextMessage($conversation->phone_number, $resultText);
        WhatsAppMessageLog::logOutgoing($conversation->id, $resultText);
    }

    /**
     * Handle AI chat — forward user question to Gemini with latest scan data.
     */
    private function handleAiChat(WhatsAppConversation $conversation, string $question): void
    {
        // Find the latest valid pricelist with packages
        $pricelist = null;
        if ($conversation->pricelist_id) {
            $candidate = Pricelist::find($conversation->pricelist_id);
            if ($candidate && $candidate->packages()->count() > 0) {
                $pricelist = $candidate;
            }
        }

        if (!$pricelist) {
            $pricelist = Pricelist::where('status', 'processed')
                ->whereHas('packages')
                ->latest()
                ->first();
        }

        if (!$pricelist || $pricelist->packages()->count() === 0) {
            $this->whatsapp->sendTextMessage(
                $conversation->phone_number,
                "ℹ️ Belum ada data pricelist yang berhasil di-scan. Silakan kirim gambar/ZIP pricelist terlebih dahulu!"
            );
            WhatsAppMessageLog::logOutgoing($conversation->id, "ℹ️ Belum ada data pricelist.");
            return;
        }

        try {
            // Reply immediately that question was received
            $ackMsg = "💬 *Pertanyaan Diterima!*\n⏳ Gemini AI sedang menganalisis data, mohon tunggu sebentar...";
            $this->whatsapp->sendTextMessage($conversation->phone_number, $ackMsg);
            WhatsAppMessageLog::logOutgoing($conversation->id, $ackMsg);

            // Get API keys
            $activeKeys = ApiKey::where('is_active', true)->pluck('key')->toArray();
            if (empty($activeKeys)) {
                $this->whatsapp->sendTextMessage(
                    $conversation->phone_number,
                    "⚠️ API Key belum dikonfigurasi. Silakan setup di dashboard."
                );
                return;
            }

            // Prepare packages data for AI
            $payload = $pricelist->packages()->get()->map(fn($p) => [
                'provider' => $p->provider,
                'package_name' => $p->package_name,
                'price' => (int) $p->price,
                'gb' => (float) $p->gb,
                'days' => (int) $p->days,
                'yield_val' => (float) $p->yield_val,
                'category' => $p->category,
            ])->toArray();

            // Collect model fallback pool
            $activeKeysData = ApiKey::where('is_active', true)->get();
            $supportedModelsPool = [];
            foreach ($activeKeysData as $keyModel) {
                if (is_array($keyModel->supported_models)) {
                    $supportedModelsPool = array_merge($supportedModelsPool, $keyModel->supported_models);
                }
            }
            $supportedModelsPool = array_unique($supportedModelsPool);

            $priority = [
                'gemini-1.5-flash',
                'gemini-1.5-flash-8b',
                'gemini-2.0-flash-exp',
                'gemini-1.5-pro',
            ];

            $finalModels = [];
            foreach ($priority as $m) {
                if (in_array($m, $supportedModelsPool)) {
                    $finalModels[] = $m;
                }
            }
            if (empty($finalModels)) {
                $finalModels = !empty($supportedModelsPool) ? $supportedModelsPool : $priority;
            }
            $modelsString = implode(',', $finalModels);

            // Send to FastAPI chat endpoint
            $response = Http::timeout(60)->post(
                env('FASTAPI_URL', 'http://127.0.0.1:8091') . '/api/chat',
                [
                    'message' => $question,
                    'packages' => $payload,
                    'api_keys' => implode(',', $activeKeys),
                    'model' => $modelsString,
                ]
            );

            if ($response->successful()) {
                $chatData = $response->json('data');
                $replyText = $chatData['text'] ?? 'Maaf, tidak bisa menghasilkan jawaban.';

                // Recursive unwrapper: guarantee text is never a JSON string or JSON code block
                for ($i = 0; $i < 3; $i++) {
                    $trimmed = trim($replyText);
                    if (str_starts_with($trimmed, '{') || str_contains($trimmed, '```')) {
                        $cleaned = preg_replace('/```(?:json)?\s*([\s\S]*?)\s*```/i', '$1', $trimmed);
                        $jsonCandidate = json_decode(trim($cleaned), true);
                        if (is_array($jsonCandidate) && isset($jsonCandidate['text'])) {
                            $replyText = $jsonCandidate['text'];
                        } else {
                            break;
                        }
                    } else {
                        break;
                    }
                }

                // Truncate for WhatsApp (max ~4096 chars)
                if (strlen($replyText) > 4000) {
                    $replyText = substr($replyText, 0, 3950) . "\n\n_... (jawaban dipotong)_";
                }

                $this->whatsapp->sendTextMessage($conversation->phone_number, $replyText);
                WhatsAppMessageLog::logOutgoing($conversation->id, $replyText);
            } else {
                throw new \RuntimeException("Chat API error: " . $response->body());
            }

        } catch (\Exception $e) {
            Log::error("WhatsApp AI chat failed: " . $e->getMessage());
            $this->whatsapp->sendTextMessage(
                $conversation->phone_number,
                "⚠️ Maaf, terjadi kesalahan saat memproses pertanyaan Anda. Silakan coba lagi."
            );
        }
    }

    // ──────────────────────────────────────────────
    // API endpoints for Dashboard monitoring
    // ──────────────────────────────────────────────

    /**
     * Get WhatsApp connection status (for dashboard).
     */
    public function connectionStatus()
    {
        return response()->json($this->whatsapp->getConnectionStatus());
    }

    /**
     * Get recent WhatsApp conversations (for dashboard).
     */
    public function conversations(Request $request)
    {
        $conversations = WhatsAppConversation::with(['messages' => function ($q) {
            $q->latest()->take(1);
        }])
            ->orderByDesc('last_message_at')
            ->take(50)
            ->get()
            ->map(function ($conv) {
                $lastMsg = $conv->messages->first();
                return [
                    'id' => $conv->id,
                    'phone_number' => $conv->phone_number,
                    'sender_name' => $conv->sender_name,
                    'status' => $conv->status,
                    'last_message' => $lastMsg?->content,
                    'last_message_type' => $lastMsg?->message_type,
                    'last_message_direction' => $lastMsg?->direction,
                    'last_message_at' => $conv->last_message_at?->toISOString(),
                    'pricelist_id' => $conv->pricelist_id,
                    'message_count' => $conv->messages()->count(),
                ];
            });

        return response()->json(['data' => $conversations]);
    }

    /**
     * Get messages for a specific conversation (for dashboard).
     */
    public function conversationMessages(int $conversationId)
    {
        $messages = WhatsAppMessageLog::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->take(100)
            ->get()
            ->map(fn($msg) => [
                'id' => $msg->id,
                'direction' => $msg->direction,
                'type' => $msg->message_type,
                'content' => $msg->content,
                'media_path' => $msg->media_path,
                'status' => $msg->status,
                'created_at' => $msg->created_at->toISOString(),
            ]);

        return response()->json(['data' => $messages]);
    }

    /**
     * Get WhatsApp stats for dashboard widgets.
     */
    public function stats()
    {
        return response()->json([
            'total_conversations' => WhatsAppConversation::count(),
            'active_today' => WhatsAppConversation::where('last_message_at', '>=', now()->startOfDay())->count(),
            'messages_today' => WhatsAppMessageLog::where('created_at', '>=', now()->startOfDay())->count(),
            'images_processed' => WhatsAppMessageLog::where('message_type', 'image')
                ->where('direction', 'incoming')
                ->count(),
            'connection' => $this->whatsapp->getConnectionStatus(),
        ]);
    }

    /** Current sender whitelist, as the comma-separated string the UI edits. */
    public function settings()
    {
        $allowed = WhatsAppSetting::allowedNumbers();

        return response()->json([
            'allowed_numbers' => in_array('*', $allowed, true) ? '' : implode(',', $allowed),
            'allow_all' => in_array('*', $allowed, true),
        ]);
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'allowed_numbers' => ['nullable', 'string', 'max:2000'],
        ]);

        $raw = trim($validated['allowed_numbers'] ?? '');

        if ($raw === '' || $raw === '*') {
            WhatsAppSetting::put(WhatsAppSetting::ALLOWED_NUMBERS, '*');

            return $this->settings();
        }

        $numbers = [];
        foreach (explode(',', $raw) as $candidate) {
            $number = WhatsAppSetting::normalizeNumber($candidate);

            // 62 + 9 digits is the shortest real Indonesian mobile number;
            // anything shorter is a typo and would silently never match.
            if (strlen($number) < 10 || strlen($number) > 15) {
                return response()->json([
                    'message' => "Nomor tidak valid: " . trim($candidate),
                ], 422);
            }

            $numbers[$number] = true;
        }

        WhatsAppSetting::put(WhatsAppSetting::ALLOWED_NUMBERS, implode(',', array_keys($numbers)));

        return $this->settings();
    }
}
