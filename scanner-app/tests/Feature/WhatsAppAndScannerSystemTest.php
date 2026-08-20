<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\ExtractedPackage;
use App\Models\Pricelist;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessageLog;
use App\Services\WhatsApp\EvolutionApiService;
use App\Services\WhatsApp\WhatsAppMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsAppAndScannerSystemTest extends TestCase
{
    // RefreshDatabase was imported but never applied, so setUp() queried
    // api_keys against an unmigrated :memory: database and every test errored.
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure test API key exists
        ApiKey::firstOrCreate(
            ['key' => 'test-api-key-12345'],
            [
                'is_active' => true,
                'supported_models' => ['gemini-1.5-flash', 'gemini-1.5-pro'],
            ]
        );
    }

    /** @test */
    public function test_evolution_api_service_bot_message_filtering(): void
    {
        $service = new EvolutionApiService();

        // 1. Simulate a bot-sent message ID cached
        $botMsgId = 'BOT_MSG_TEST_999';
        Cache::put("bot_sent_msg_{$botMsgId}", true, 60);

        $requestBot = new Request([
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'id' => $botMsgId,
                    'fromMe' => true,
                    'remoteJid' => '6285842041644@s.whatsapp.net',
                ],
                'message' => [
                    'conversation' => 'Bot reply message',
                ],
            ],
        ]);

        $parsedBot = $service->parseIncomingMessage($requestBot);
        $this->assertNull($parsedBot, 'Bot-sent messages should be skipped');

        // 2. Simulate a real user message (even in self-chat fromMe = true)
        $userMsgId = 'USER_MSG_TEST_111';
        $requestUser = new Request([
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'id' => $userMsgId,
                    'fromMe' => true,
                    'remoteJid' => '6285842041644@s.whatsapp.net',
                ],
                'message' => [
                    'conversation' => 'brand mana yang memiliki performa yield terbaik',
                ],
                'pushName' => 'Tester',
            ],
        ]);

        $parsedUser = $service->parseIncomingMessage($requestUser);
        $this->assertNotNull($parsedUser, 'User self-messages should be parsed');
        $this->assertEquals('6285842041644', $parsedUser->from);
        $this->assertEquals('brand mana yang memiliki performa yield terbaik', $parsedUser->text);
    }

    /** @test */
    public function test_whatsapp_webhook_keyword_routing(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'RESP_123']], 200),
        ]);

        // Test 'help' keyword
        $responseHelp = $this->postJson('/api/whatsapp/webhook', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'id' => 'MSG_HELP_001',
                    'fromMe' => false,
                    'remoteJid' => '6281234567890@s.whatsapp.net',
                ],
                'message' => [
                    'conversation' => 'help',
                ],
                'pushName' => 'Tester',
            ],
        ]);

        $responseHelp->assertStatus(200);

        $conversation = WhatsAppConversation::where('phone_number', '6281234567890')->first();
        $this->assertNotNull($conversation);

        $lastLog = WhatsAppMessageLog::where('conversation_id', $conversation->id)->latest()->first();
        $this->assertNotNull($lastLog);
        $this->assertStringContainsString('Pricelist Scanner Bot', $lastLog->content);
    }

    /** @test */
    public function test_whatsapp_reset_session_keyword(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'RESP_124']], 200),
        ]);

        $pricelist = Pricelist::create([
            'filename' => 'WA: 2026-08-13 16:50:00',
            'status' => 'processed',
        ]);

        $conversation = WhatsAppConversation::create([
            'phone_number' => '62899998888',
            'pricelist_id' => $pricelist->id,
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'id' => 'MSG_RESET_001',
                    'fromMe' => false,
                    'remoteJid' => '62899998888@s.whatsapp.net',
                ],
                'message' => [
                    'conversation' => 'baru',
                ],
            ],
        ])->assertStatus(200);

        $conversation->refresh();
        $this->assertNull($conversation->pricelist_id, 'Sesi baru command should clear pricelist_id');
    }

    /** @test */
    public function test_extracted_package_integer_casting_and_yield_calculation(): void
    {
        $pricelist = Pricelist::create([
            'filename' => 'WA: 2026-08-13 16:55:00',
            'status' => 'processed',
        ]);

        // Test float yield_val (e.g. 5428.57) cast to integer 5429
        $floatYield = 5428.57;
        $pkg = ExtractedPackage::create([
            'pricelist_id' => $pricelist->id,
            'provider' => 'AXIS',
            'package_name' => 'Bronet 7GB',
            'price' => 38000,
            'gb' => 7.0,
            'days' => 30,
            'yield_val' => (int) round((float) $floatYield),
            'category' => 'Bulanan (Standar)',
            'is_anomaly' => false,
        ]);

        $this->assertDatabaseHas('extracted_packages', [
            'id' => $pkg->id,
            'provider' => 'AXIS',
            'yield_val' => 5429,
        ]);
    }

    /** @test */
    public function test_ai_chat_response_formatting_and_smart_fallback(): void
    {
        Http::fake([
            '*/message/sendText/*' => Http::response(['key' => ['id' => 'RESP_125']], 200),
            '*/api/chat' => Http::response([
                'status' => 'success',
                'data' => [
                    'text' => "📌 *Statement:*\nTelkomsel terbaik.\n\n📊 *Bukti Berdasarkan Data:*\nYield Rp 3000.\n\n💡 *Insight & Rekomendasi:*\nSangat murah.",
                    'chart_config' => null,
                ]
            ], 200),
        ]);

        $pricelist = Pricelist::create([
            'filename' => 'WA: 2026-08-13 16:56:00',
            'status' => 'processed',
        ]);

        ExtractedPackage::create([
            'pricelist_id' => $pricelist->id,
            'provider' => 'TELKOMSEL',
            'package_name' => 'OMG 10GB',
            'price' => 30000,
            'gb' => 10.0,
            'days' => 30,
            'yield_val' => 3000,
            'category' => 'Bulanan (Standar)',
            'is_anomaly' => false,
        ]);

        // Conversation with null pricelist_id should fallback to the valid pricelist with packages
        $conversation = WhatsAppConversation::create([
            'phone_number' => '62877776666',
            'pricelist_id' => null,
        ]);

        $this->postJson('/api/whatsapp/webhook', [
            'event' => 'messages.upsert',
            'data' => [
                'key' => [
                    'id' => 'MSG_CHAT_001',
                    'fromMe' => false,
                    'remoteJid' => '62877776666@s.whatsapp.net',
                ],
                'message' => [
                    'conversation' => 'brand mana yang memiliki performa yield terbaik',
                ],
            ],
        ])->assertStatus(200);

        $logs = WhatsAppMessageLog::where('conversation_id', $conversation->id)->get();
        $this->assertGreaterThanOrEqual(2, $logs->count(), 'Should log ACK message and AI answer');

        $ackMsg = $logs->first(fn ($l) => str_contains((string) $l->content, 'Pertanyaan Diterima'));
        $this->assertNotNull($ackMsg, 'Should send immediate ACK message');

        $aiMsg = $logs->last();
        $this->assertStringContainsString('📌 *Statement:*', $aiMsg->content);
        $this->assertStringContainsString('📊 *Bukti Berdasarkan Data:*', $aiMsg->content);
        $this->assertStringContainsString('💡 *Insight & Rekomendasi:*', $aiMsg->content);
        $this->assertStringNotContainsString('{"action":', $aiMsg->content, 'Should not contain raw JSON');
    }
}
