<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, onMounted, onUnmounted, nextTick, computed } from "vue";
import axios from "axios";
import ChartViewer from "@/Components/ChartViewer.vue";

const globalErrorMsg = ref("");

// Global error handler for axios requests
const showError = (e, fallback) => {
    let msg = fallback;
    if (e.response?.data?.error) {
        msg = e.response.data.error;
    } else if (e.response?.data?.message) {
        msg = e.response.data.message;
    }

    // Check if it's a validation error
    if (e.response?.data?.errors) {
        const errors = Object.values(e.response.data.errors).flat();
        if (errors.length > 0) msg = errors.join("\n");
    }

    globalErrorMsg.value = "Terjadi Kesalahan: " + msg;
    console.error("Axios Error:", e.response || e);
    
    // Auto-hide after 5 seconds
    setTimeout(() => {
        globalErrorMsg.value = "";
    }, 5000);
};
import { marked } from "marked";

const props = defineProps({
    pricelists: Array,
});

// ─── State ────────────────────────────────────────────────────────
const form = useForm({ message: "", images: [] });
const fileInput = ref(null);
const chatContainer = ref(null);
const sidebarOpen = ref(true);
const sidebarTab = ref("history"); // 'history' | 'keys' | 'models'
const isChatOpen = ref(false); // Controls chat popup visibility

const activeSessionId = ref(null);

// VLR Checker State
const isVlrModalOpen = ref(false);
const vlrPhoneNumbers = ref("");
const isVlrChecking = ref(false);
const vlrResults = ref([]);
const vlrErrorMessage = ref("");

const guessProvider = (number) => {
    let clean = number.replace(/\D/g, '');
    if (clean.startsWith('62')) clean = '0' + clean.slice(2);
    
    if (clean.match(/^08(11|12|13|21|22|23|52|53|51)/)) return 'TELKOMSEL';
    if (clean.match(/^08(17|18|19|59|77|78)/)) return 'XL';
    if (clean.match(/^08(31|32|33|38)/)) return 'AXIS';
    if (clean.match(/^08(14|15|16|55|56|57|58)/)) return 'IM3';
    if (clean.match(/^08(95|96|97|98|99)/)) return '3ID';
    if (clean.match(/^08(81|82|83|84|85|86|87|88|89)/)) return 'SMARTFREN';
    
    return 'UNKNOWN';
};

const checkVlrNumbers = async () => {
    if (!vlrPhoneNumbers.value.trim()) {
        vlrErrorMessage.value = 'Silakan masukkan nomor telepon.';
        return;
    }

    vlrErrorMessage.value = '';
    isVlrChecking.value = true;
    
    const list = vlrPhoneNumbers.value.split('\n').map(n => n.trim()).filter(n => n.length > 5);
    const newResults = [];
    
    for (const number of list) {
        const provider = guessProvider(number);
        try {
            const res = await axios.post('/vlr-checker/check', {
                phone_number: number,
                provider: provider
            });
            if (res.data.status === 'success') {
                newResults.push({ number, provider, age: res.data.data.age_in_days, type: res.data.data.product_type, status: 'success' });
            } else {
                newResults.push({ number, provider, age: '-', type: 'Error: ' + (res.data.message || 'Unknown'), status: 'error' });
            }
        } catch (e) {
            newResults.push({ number, provider, age: '-', type: 'Gagal Terhubung', status: 'error' });
        }
    }
    
    vlrResults.value = newResults;
    isVlrChecking.value = false;
};

// Data Table & Insights toggles (per pricelist id)
const activeTables = ref({});
const activeInsights = ref({});
const chatInputs = ref({});
const chatLoading = ref({});

// API Key management
const apiKeys = ref([]);
const newKeyInput = ref("");
const keyLoading = ref(false);

// ─── Computed ────────────────────────────────────────────────────
const sortedPricelists = computed(() => {
    return [...props.pricelists].sort(
        (a, b) => new Date(a.created_at) - new Date(b.created_at),
    );
});

const activeSession = computed(() => {
    if (!activeSessionId.value) return null;
    return props.pricelists.find((p) => p.id === activeSessionId.value);
});

const activeKeyCount = computed(
    () => apiKeys.value.filter((k) => k.is_active).length,
);
const totalUsage = computed(() =>
    apiKeys.value.reduce((sum, k) => sum + k.usage_count, 0),
);

// ─── Actions ────────────────────────────────────────────────────
const scrollToBottom = () => {
    nextTick(() => {
        if (chatContainer.value)
            chatContainer.value.scrollTop = chatContainer.value.scrollHeight;
    });
};

const parseMarkdown = (text) => {
    if (!text) return "";
    return marked.parse(text);
};

const submit = () => {
    if (form.images.length === 0 && !form.message.trim()) return;

    if (form.images.length === 0 && activeSessionId.value) {
        // Text only for an existing session -> use the chat API
        chatInputs.value[activeSessionId.value] = form.message;
        form.message = "";
        sendChat(activeSession.value);
        scrollToBottom();
        return;
    }

    form.transform((data) => ({
        ...data,
        pricelist_id: activeSessionId.value,
        is_append: !!activeSessionId.value,
    })).post(route("scanner.store"), {
        preserveScroll: true,
        onSuccess: (page) => {
            form.reset();
            form.images = [];
            if (fileInput.value) fileInput.value.value = "";
            if (!activeSessionId.value && page.props.pricelists.length > 0) {
                const newest = [...page.props.pricelists].sort(
                    (a, b) => new Date(b.created_at) - new Date(a.created_at),
                )[0];
                activeSessionId.value = newest.id;
            }
            scrollToBottom();
        },
    });
};

const bestOffersByValidity = (packages) => {
    if (!packages || packages.length === 0) return [];

    // Group by validity days ranges (exclude monthly)
    const sachet = packages.filter(
        (p) =>
            !p.category?.toLowerCase().includes("monthly") &&
            Number(p.days) < 28,
    );

    const rules = [
        { label: "1 day", check: (d) => d === 1 },
        { label: "2 days", check: (d) => d === 2 },
        { label: "3 days", check: (d) => d === 3 },
        { label: "5 days", check: (d) => d === 5 },
        { label: "7 days", check: (d) => d === 7 },
        { label: ">10 days", check: (d) => d >= 10 },
    ];

    const results = [];
    rules.forEach((rule) => {
        const matches = sachet.filter((p) => rule.check(Number(p.days)));
        if (matches.length > 0) {
            matches.sort((a, b) => Number(a.yield_val) - Number(b.yield_val));
            const best = matches[0];
            results.push({
                label: rule.label,
                gb: best.gb,
                price: Math.round(Number(best.price) / 1000), // convert to K
                provider: best.provider,
            });
        }
    });
    return results;
};

const bestOffersMonthly = (packages) => {
    if (!packages || packages.length === 0) return [];

    const monthly = packages.filter(
        (p) =>
            p.category?.toLowerCase().includes("monthly") ||
            Number(p.days) >= 28,
    );

    const rules = [
        { label: "30K - 50K", check: (p) => p >= 30000 && p <= 50000 },
        { label: "50K - 100K", check: (p) => p > 50000 && p <= 100000 },
        { label: "> 100K", check: (p) => p > 100000 },
    ];

    const results = [];
    rules.forEach((rule) => {
        const matches = monthly.filter((pkg) => rule.check(Number(pkg.price)));
        if (matches.length > 0) {
            matches.sort((a, b) => Number(a.yield_val) - Number(b.yield_val));
            const best = matches[0];
            results.push({
                label: rule.label,
                gb: best.gb,
                price: Math.round(Number(best.price) / 1000),
                provider: best.provider,
            });
        }
    });
    return results;
};

const downloadExcel = (session) => {
    window.open(route("scanner.export", session.id), "_blank");
};

const toggleTable = (id) => {
    activeTables.value[id] = !activeTables.value[id];
};

const loadInsights = async (id) => {
    if (activeInsights.value[id]) {
        activeInsights.value[id] = null;
        return;
    }
    try {
        activeInsights.value[id] = { loading: true };
        const res = await axios.get(route("scanner.insights", id));
        activeInsights.value[id] = { loading: false, data: res.data.data };
    } catch (e) {
        activeInsights.value[id] = {
            loading: false,
            error: "Failed to load insights",
        };
    }
};

const sendChat = async (pricelist) => {
    const msg = chatInputs.value[pricelist.id]?.trim();
    if (!msg) return;

    if (!pricelist.chat_messages) {
        pricelist.chat_messages = [];
    }

    // Optimistic UI
    pricelist.chat_messages.push({
        id: Date.now(),
        role: "user",
        content: msg,
    });

    chatInputs.value[pricelist.id] = "";
    chatLoading.value[pricelist.id] = true;

    try {
        const res = await axios.post(route("scanner.chat", pricelist.id), {
            message: msg,
        });
        // Add AI response
        pricelist.chat_messages.push(res.data.assistant_message);
    } catch (e) {
        showError(e, "Gagal mengirim pesan.");
    }

    chatLoading.value[pricelist.id] = false;
};

const deleteSession = (id) => {
    if (!confirm("Yakin hapus sesi ini beserta semua data dan gambarnya?"))
        return;
    router.delete(route("scanner.destroy", id), {
        onSuccess: () => {
            if (activeSessionId.value === id) {
                activeSessionId.value = null;
            }
        },
    });
};

const editingPrompt = ref({});
const retryLoading = ref({});
const cancelLoading = ref({});

const deleteChart = async (pricelist, msg) => {
    if (!confirm("Apakah Anda yakin ingin menghapus grafik ini dari dashboard?")) return;
    
    try {
        await axios.delete(route('scanner.chat.destroyChart', { pricelist: pricelist.id, chatMessage: msg.id }));
        // Hapus chart_config dari state lokal
        msg.chart_config = null;
    } catch (e) {
        showError(e, "Gagal menghapus grafik.");
    }
};

const cancelScan = async (id) => {
    if (!confirm("Batalkan proses scan ini?")) return;
    cancelLoading.value[id] = true;
    try {
        await axios.post(route("scanner.cancel", id));
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        showError(e, "Gagal membatalkan scan.");
    }
    cancelLoading.value[id] = false;
};

const retryScan = async (id) => {
    if (!confirm("Ulangi proses scan untuk sesi ini?")) return;
    retryLoading.value[id] = true;
    try {
        await axios.post(route("scanner.retry", id));
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        showError(e, "Gagal mengulangi scan.");
    }
    retryLoading.value[id] = false;
};

// Editable Table States
const editablePackages = ref({});
const isEditingTable = ref({});
const savingTable = ref({});

const toggleEditTable = (listId, packages) => {
    if (isEditingTable.value[listId]) {
        isEditingTable.value[listId] = false;
    } else {
        editablePackages.value[listId] = JSON.parse(JSON.stringify(packages));
        isEditingTable.value[listId] = true;
        activeTables.value[listId] = true;
    }
};

const addEmptyRow = (listId) => {
    editablePackages.value[listId].push({
        provider: "TSEL",
        category: "Harian (Sachet)",
        product_type: "Isi Ulang",
        gb: 0,
        days: 1,
        price: 0,
        yield_val: 0,
    });
};

const insertRowAfter = (listId, index) => {
    editablePackages.value[listId].splice(index + 1, 0, {
        provider: "TSEL",
        category: "Harian (Sachet)",
        product_type: "Isi Ulang",
        gb: 0,
        days: 1,
        price: 0,
        yield_val: 0,
    });
};

const deleteRow = (listId, index) => {
    editablePackages.value[listId].splice(index, 1);
};

const savePackages = async (listId) => {
    savingTable.value[listId] = true;
    try {
        await axios.put(route("scanner.packages.update", listId), {
            packages: editablePackages.value[listId],
        });
        isEditingTable.value[listId] = false;
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        showError(e, "Gagal menyimpan data");
    }
    savingTable.value[listId] = false;
};

const openEditPrompt = (list) => {
    const firstMsg = list.chat_messages?.find(
        (m) => m.attachments && m.attachments.length > 0,
    );
    editingPrompt.value[list.id] = {
        messageId: firstMsg ? firstMsg.id : null,
        content: firstMsg ? firstMsg.content : "Tolong scan gambar ini.",
    };
};

const openImage = (url) => {
    window.open("/storage/" + url, "_blank");
};

const savePromptAndRetry = async (list) => {
    const editData = editingPrompt.value[list.id];
    if (!editData || !editData.messageId) {
        globalErrorMsg.value = "Tidak dapat menemukan pesan awal untuk diedit.";
        return;
    }

    retryLoading.value[list.id] = true;
    try {
        await axios.put(
            route("scanner.message.update", {
                pricelist: list.id,
                chatMessage: editData.messageId,
            }),
            {
                content: editData.content,
            },
        );

        await axios.post(route("scanner.retry", list.id));

        editingPrompt.value[list.id].show = false;
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        showError(e, "Gagal menyimpan prompt & mengulangi scan.");
    }
    retryLoading.value[list.id] = false;
};

const renameSessionId = ref(null);
const renameTitle = ref("");

const startRename = (list) => {
    renameSessionId.value = list.id;
    renameTitle.value = list.filename;
};

const saveRename = async (list) => {
    if (!renameTitle.value.trim()) {
        renameSessionId.value = null;
        return;
    }
    try {
        await axios.put(route("scanner.rename", list.id), {
            filename: renameTitle.value,
        });
        renameSessionId.value = null;
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        showError(e, "Gagal mengubah nama.");
    }
};

const newChat = () => {
    activeSessionId.value = null;
    form.reset();
};

// API Key CRUD
const fetchKeys = async () => {
    try {
        const res = await axios.get(route("apikeys.index"));
        apiKeys.value = res.data;
    } catch (e) {
        console.error(e);
    }
};

const addKey = async () => {
    if (!newKeyInput.value.trim()) return;
    keyLoading.value = true;
    try {
        await axios.post(route("apikeys.store"), {
            key: newKeyInput.value.trim(),
        });
        newKeyInput.value = "";
        await fetchKeys();
    } catch (e) {
        showError(e, "Failed to add key");
    }
    keyLoading.value = false;
};

const deleteKey = async (id) => {
    if (!confirm("Yakin hapus API Key ini?")) return;
    try {
        await axios.delete(route("apikeys.destroy", id));
        await fetchKeys();
    } catch (e) {
        console.error(e);
    }
};

const toggleKey = async (id) => {
    try {
        await axios.post(route("apikeys.toggle", id));
        await fetchKeys();
    } catch (e) {
        console.error(e);
    }
};

// Available models info
const availableModels = ref([
    {
        name: "Gemini 2.5 Flash",
        id: "gemini-2.5-flash",
        tier: "Free",
        rpm: 10,
        rpd: 500,
    },
    {
        name: "Gemini 2.0 Flash",
        id: "gemini-2.0-flash",
        tier: "Free",
        rpm: 15,
        rpd: 1500,
    },
    {
        name: "Gemini 1.5 Flash",
        id: "gemini-1.5-flash",
        tier: "Free",
        rpm: 15,
        rpd: 1500,
    },
    {
        name: "Gemini 1.5 Pro",
        id: "gemini-1.5-pro",
        tier: "Free",
        rpm: 2,
        rpd: 50,
    },
]);

// ─── Lifecycle ────────────────────────────────────────────────────
let pollTimer = null;
const pollStatus = () => {
    const hasPending = props.pricelists.some((l) =>
        [
            "pending",
            "processing",
            "Mengekstrak data dari gambar...",
            "Menyusun insight & benchmarking...",
        ].includes(l.status),
    );

    if (hasPending) {
        router.reload({
            only: ["pricelists"],
            preserveState: true,
            preserveScroll: true,
            onFinish: () => {
                pollTimer = setTimeout(pollStatus, 2000);
            },
        });
    } else {
        pollTimer = setTimeout(pollStatus, 2000);
    }
};

onMounted(() => {
    scrollToBottom();
    fetchKeys();
    pollStatus();
});
onUnmounted(() => {
    clearTimeout(pollTimer);
});
</script>

<template>
    <Head title="SmartScan AI" />
    <div
        class="h-screen flex bg-[#131314] text-gray-100 font-sans overflow-hidden"
    >
        <!-- SIDEBAR -->
        <div
            :class="sidebarOpen ? 'w-72' : 'w-0 opacity-0'"
            class="flex-shrink-0 bg-[#1e1e20] flex flex-col transition-all duration-300 overflow-hidden border-r border-gray-800"
        >
            <!-- Sidebar Header & New Chat -->
            <div
                class="p-4 border-b border-gray-800 flex items-center justify-between"
            >
                <h2
                    class="text-lg font-semibold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400"
                >
                    SmartScan AI
                </h2>
                <button
                    @click="sidebarOpen = false"
                    class="p-1 hover:bg-gray-700 rounded-lg transition text-gray-400"
                >
                    <svg
                        class="w-5 h-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M11 19l-7-7 7-7m8 14l-7-7 7-7"
                        ></path>
                    </svg>
                </button>
            </div>
            <!-- Status & Input API Key -->
            <div class="p-3 border-b border-gray-800 space-y-4">
                <!-- Status Usage -->
                <div class="bg-[#2a2a2c] rounded-xl p-3 border border-gray-700">
                    <div class="text-xs text-gray-400 mb-1">
                        Kapasitas Model
                    </div>
                    <div class="flex items-end justify-between">
                        <div>
                            <div class="text-xl font-bold text-blue-400">
                                {{
                                    Math.max(
                                        0,
                                        activeKeyCount * 1500 - totalUsage,
                                    )
                                }}
                            </div>
                            <div class="text-[10px] text-gray-500">
                                Permintaan tersisa
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-gray-300">
                                {{ activeKeyCount }} Key
                            </div>
                            <div class="text-[10px] text-gray-500">Aktif</div>
                        </div>
                    </div>
                    <!-- Progress Bar -->
                    <div
                        class="w-full bg-gray-800 rounded-full h-1.5 mt-2 overflow-hidden"
                        title="Persentase Penggunaan"
                    >
                        <div
                            class="bg-gradient-to-r from-blue-500 to-indigo-500 h-1.5 rounded-full transition-all duration-500"
                            :style="`width: ${Math.min(100, (totalUsage / Math.max(1, activeKeyCount * 1500)) * 100)}%`"
                        ></div>
                    </div>
                </div>

                <!-- Input API Key -->
                <form @submit.prevent="addKey" class="flex gap-2">
                    <input
                        v-model="newKeyInput"
                        type="password"
                        placeholder="Masukkan API Key Gemini..."
                        class="w-full bg-[#1e1e20] border border-gray-700 rounded-lg px-3 py-2 text-xs text-gray-200 placeholder-gray-500 focus:outline-none focus:border-blue-500 transition"
                        required
                    />
                    <button
                        type="submit"
                        :disabled="keyLoading"
                        class="bg-[#2a2a2c] hover:bg-[#353538] border border-gray-700 text-gray-300 rounded-lg px-3 py-2 transition flex items-center justify-center shrink-0 disabled:opacity-50"
                    >
                        <svg
                            v-if="keyLoading"
                            class="w-4 h-4 animate-spin text-blue-400"
                            fill="none"
                            viewBox="0 0 24 24"
                        >
                            <circle
                                class="opacity-25"
                                cx="12"
                                cy="12"
                                r="10"
                                stroke="currentColor"
                                stroke-width="4"
                            ></circle>
                            <path
                                class="opacity-75"
                                fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                            ></path>
                        </svg>
                        <svg
                            v-else
                            class="w-4 h-4 text-blue-400"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 4v16m8-8H4"
                            ></path>
                        </svg>
                    </button>
                </form>

                <button
                    @click="newChat"
                    class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-[#2a2a2c] hover:bg-[#353538] text-sm font-medium rounded-lg transition border border-gray-700"
                >
                    <svg
                        class="w-4 h-4"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M12 4v16m8-8H4"
                        ></path>
                    </svg>
                    Percakapan Baru
                </button>

                <button
                    @click="isVlrModalOpen = true"
                    class="w-full mt-2 flex items-center justify-center gap-2 px-4 py-2.5 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white text-sm font-medium rounded-lg transition shadow-[0_0_15px_rgba(59,130,246,0.3)] border border-blue-500"
                >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"></path>
                    </svg>
                    Cek VLR (Umur Kartu)
                </button>
            </div>

            <!-- History List -->
            <div
                class="flex-1 overflow-y-auto px-3 pb-4 space-y-1 custom-scrollbar"
            >
                <div
                    class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 mt-4 px-2"
                >
                    Terbaru
                </div>

                <div
                    v-for="list in sortedPricelists"
                    :key="list.id"
                    class="group flex items-center justify-between p-2 rounded-lg cursor-pointer transition text-sm"
                    :class="
                        activeSessionId === list.id
                            ? 'bg-[#2a2a2c] text-white'
                            : 'text-gray-300 hover:bg-[#202022] hover:text-white'
                    "
                    @click="activeSessionId = list.id"
                >
                    <div class="flex items-center gap-3 overflow-hidden flex-1">
                        <svg
                            class="w-4 h-4 text-gray-500 shrink-0"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"
                            ></path>
                        </svg>

                        <input
                            v-if="renameSessionId === list.id"
                            v-model="renameTitle"
                            @keyup.enter="saveRename(list)"
                            @blur="saveRename(list)"
                            @click.stop
                            class="bg-[#131314] text-white text-sm px-2 py-1 rounded border border-blue-500 outline-none w-full"
                            autofocus
                        />
                        <span v-else class="truncate">{{ list.filename }}</span>
                    </div>

                    <div
                        v-if="renameSessionId !== list.id"
                        class="flex items-center opacity-0 group-hover:opacity-100 transition shrink-0 ml-2"
                    >
                        <button
                            @click.stop="startRename(list)"
                            class="p-1 hover:bg-gray-600 rounded text-gray-400 hover:text-white"
                            title="Rename"
                        >
                            <svg
                                class="w-3.5 h-3.5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                ></path>
                            </svg>
                        </button>
                        <button
                            @click.stop="deleteSession(list.id)"
                            class="p-1 hover:bg-red-900/50 rounded text-gray-400 hover:text-red-400"
                            title="Delete"
                        >
                            <svg
                                class="w-3.5 h-3.5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                ></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            <div
                class="p-4 border-t border-gray-800 text-xs text-gray-500 flex justify-between items-center"
            >
                <span>API Keys: {{ activeKeyCount }} active</span>
                <span title="Total Usage">{{ totalUsage }} reqs</span>
            </div>
        </div>

        <!-- MAIN AREA -->
        <div class="flex-1 flex flex-col h-screen relative bg-[#131314]">
            <!-- Topbar (Mobile Hamburger) -->
            <div
                class="h-14 flex items-center px-4 border-b border-gray-800 shrink-0 bg-[#1e1e20]/50 backdrop-blur"
            >
                <button
                    v-if="!sidebarOpen"
                    @click="sidebarOpen = true"
                    class="p-2 hover:bg-gray-800 rounded-lg text-gray-400 transition"
                >
                    <svg
                        class="w-5 h-5"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16"
                        ></path>
                    </svg>
                </button>
                <div class="ml-auto flex items-center gap-2">
                    <span
                        v-if="activeSession"
                        class="text-sm text-gray-300 font-medium"
                        >{{ activeSession.filename }}</span
                    >
                </div>
            </div>

            <!-- DASHBOARD OUTPUT AREA -->
            <div
                class="flex-1 overflow-y-auto px-6 py-8 scroll-smooth custom-scrollbar"
            >
                <div class="max-w-7xl mx-auto space-y-8 pb-20">
                    <!-- EMPTY STATE / MAIN INPUT AREA -->
                    <div
                        v-if="!activeSession"
                        class="flex flex-col w-full max-w-4xl mx-auto mt-6"
                    >
                        <div class="text-center mb-8">
                            <h1
                                class="text-4xl font-bold mb-3 text-white tracking-tight"
                            >
                                SmartScan AI
                            </h1>
                            <p class="text-gray-400 text-lg">
                                Pilih modul dashboard dan unggah file untuk
                                dianalisis.
                            </p>
                        </div>

                        <!-- Drag & Drop Zone (Input File Image) -->
                        <label
                            class="w-full h-64 border-2 border-dashed border-gray-700 hover:border-indigo-500 hover:bg-[#1e1e20] transition-all rounded-3xl flex flex-col items-center justify-center cursor-pointer group mb-12 shadow-[0_10px_30px_rgba(0,0,0,0.3)] relative overflow-hidden bg-[#161618]"
                        >
                            <input
                                type="file"
                                accept="image/*,.zip"
                                multiple
                                @change="
                                    (e) => {
                                        form.images = Array.from(
                                            e.target.files,
                                        );
                                        submit();
                                    }
                                "
                                class="hidden"
                                :disabled="form.processing"
                            />

                            <!-- Loading Overlay when Processing -->
                            <div
                                v-if="form.processing"
                                class="absolute inset-0 bg-[#161618]/90 backdrop-blur flex flex-col items-center justify-center z-10 transition-all"
                            >
                                <div
                                    class="w-12 h-12 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin mb-4"
                                ></div>
                                <span
                                    class="font-semibold text-indigo-400 text-lg tracking-wide animate-pulse"
                                    >Mengunggah & Membuat Sesi...</span
                                >
                            </div>

                            <div
                                class="w-20 h-20 bg-indigo-500/10 rounded-full flex items-center justify-center mb-5 group-hover:scale-110 group-hover:shadow-[0_0_30px_rgba(99,102,241,0.3)] transition-all group-hover:bg-indigo-500/20"
                            >
                                <svg
                                    class="w-10 h-10 text-indigo-400"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"
                                    ></path>
                                </svg>
                            </div>
                            <span
                                class="text-xl font-bold text-gray-200 mb-2 group-hover:text-indigo-300 transition-colors"
                                >Pilih atau Tarik Gambar / ZIP ke Sini</span
                            >
                            <span
                                class="text-sm text-gray-500 bg-black/20 px-3 py-1 rounded-full"
                                >Mendukung format JPG, PNG, dan ZIP</span
                            >
                        </label>
                    </div>

                    <!-- ACTIVE SESSION OUTPUTS -->
                    <template v-else>
                        <!-- Top Action Bar -->
                        <div
                            class="flex justify-between items-center bg-[#1e1e20] p-4 rounded-2xl border border-gray-800 shadow-sm mb-6"
                        >
                            <h2 class="text-xl font-bold text-white">
                                {{ activeSession.filename }}
                            </h2>
                            <div class="flex gap-3">
                                <label
                                    class="cursor-pointer px-4 py-2 bg-blue-600/20 text-blue-400 hover:bg-blue-600/30 border border-blue-800/50 rounded-lg text-sm font-medium transition flex items-center gap-2"
                                    :class="{
                                        'opacity-50 cursor-not-allowed':
                                            form.processing,
                                    }"
                                    title="Tambahkan gambar/data baru ke sesi ini"
                                >
                                    <svg
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M12 4v16m8-8H4"
                                        ></path>
                                    </svg>
                                    Tambah Gambar
                                    <input
                                        type="file"
                                        accept="image/*,.zip"
                                        multiple
                                        @change="
                                            (e) => {
                                                form.images = Array.from(
                                                    e.target.files,
                                                );
                                                submit();
                                            }
                                        "
                                        class="hidden"
                                        :disabled="form.processing"
                                    />
                                </label>
                                <button
                                    v-if="
                                        activeSession.packages &&
                                        activeSession.packages.length > 0
                                    "
                                    @click="downloadExcel(activeSession)"
                                    class="px-4 py-2 bg-green-600/20 text-green-400 hover:bg-green-600/30 border border-green-800/50 rounded-lg text-sm font-medium transition flex items-center gap-2"
                                >
                                    <svg
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                                        ></path>
                                    </svg>
                                    Download Excel
                                </button>
                                <button
                                    @click="isChatOpen = true"
                                    class="px-4 py-2 bg-indigo-600/20 text-indigo-400 hover:bg-indigo-600/30 rounded-lg text-sm font-medium transition flex items-center gap-2"
                                >
                                    <svg
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"
                                        ></path>
                                    </svg>
                                    Buka Chat
                                </button>
                            </div>
                        </div>

                        <!-- Processing Status Indicator -->
                        <div
                            v-if="
                                [
                                    'pending',
                                    'processing',
                                    'Mengekstrak data dari gambar...',
                                    'Menyusun insight & benchmarking...',
                                ].includes(activeSession.status)
                            "
                            class="bg-[#1e1e20] p-5 rounded-2xl border border-blue-500/30 flex items-center justify-between shadow-lg mb-6"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="w-6 h-6 border-2 border-blue-400 border-t-transparent rounded-full animate-spin"
                                ></div>
                                <span
                                    class="text-base font-medium text-blue-300 animate-pulse"
                                    >Memproses:
                                    {{
                                        activeSession.status === "pending"
                                            ? "Menunggu antrean..."
                                            : activeSession.status
                                    }}</span
                                >
                            </div>
                            <button
                                @click="cancelScan(activeSession.id)"
                                class="px-3 py-1.5 bg-red-600/20 text-red-400 hover:bg-red-600/40 border border-red-800 rounded-lg transition text-sm font-medium flex items-center gap-1.5"
                                :disabled="cancelLoading[activeSession.id]"
                            >
                                <svg
                                    v-if="cancelLoading[activeSession.id]"
                                    class="w-3.5 h-3.5 animate-spin"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle
                                        class="opacity-25"
                                        cx="12"
                                        cy="12"
                                        r="10"
                                        stroke="currentColor"
                                        stroke-width="4"
                                    ></circle>
                                    <path
                                        class="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                    ></path>
                                </svg>
                                <svg
                                    v-else
                                    class="w-3.5 h-3.5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    ></path>
                                </svg>
                                Batalkan
                            </button>
                        </div>

                        <!-- Error State Processing -->
                        <div
                            v-if="activeSession.status === 'failed'"
                            class="bg-red-900/10 border border-red-900/50 rounded-2xl p-6 flex items-start gap-4 mb-6"
                        >
                            <svg
                                class="w-8 h-8 text-red-500 shrink-0"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                ></path>
                            </svg>
                            <div>
                                <h4 class="font-bold text-red-400 text-lg mb-2">
                                    Gagal Memproses Data
                                </h4>
                                <p class="text-red-300/80 mb-4">
                                    {{ activeSession.error_message }}
                                </p>
                                <button
                                    @click="retryScan(activeSession.id)"
                                    class="px-4 py-2 bg-red-600/20 text-red-400 hover:bg-red-600/40 border border-red-800 rounded-lg transition flex items-center gap-2 font-medium"
                                    :disabled="retryLoading[activeSession.id]"
                                >
                                    <svg
                                        v-if="retryLoading[activeSession.id]"
                                        class="w-4 h-4 animate-spin"
                                        fill="none"
                                        viewBox="0 0 24 24"
                                    >
                                        <circle
                                            class="opacity-25"
                                            cx="12"
                                            cy="12"
                                            r="10"
                                            stroke="currentColor"
                                            stroke-width="4"
                                        ></circle>
                                        <path
                                            class="opacity-75"
                                            fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                        ></path>
                                    </svg>
                                    <svg
                                        v-else
                                        class="w-4 h-4"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"
                                        ></path>
                                    </svg>
                                    {{
                                        retryLoading[activeSession.id]
                                            ? "Mengulangi..."
                                            : "Ulangi Scan"
                                    }}
                                </button>
                            </div>
                        </div>

                        <!-- Summarize Insights Widget (Competitor Benchmark Notes) -->
                        <div
                            v-if="
                                activeSession.packages &&
                                activeSession.packages.length > 0
                            "
                            class="bg-[#1e1e20] border border-blue-900/50 rounded-2xl overflow-hidden shadow-xl mb-6 relative"
                        >
                            <!-- Background accent -->
                            <div
                                class="absolute top-0 left-0 w-1 h-full bg-blue-500"
                            ></div>

                            <div
                                class="px-6 py-4 border-b border-gray-800 bg-[#252528]/50"
                            >
                                <h3
                                    class="font-bold text-white flex items-center gap-2"
                                >
                                    <svg
                                        class="w-5 h-5 text-blue-400"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                                        ></path>
                                    </svg>
                                    Summarize Insight (Competitor Benchmark)
                                </h3>
                            </div>

                            <div class="p-6">
                                <div
                                    class="grid grid-cols-1 md:grid-cols-2 gap-8"
                                >
                                    <div>
                                        <h4
                                            class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-4 border-b border-gray-800 pb-2"
                                        >
                                            The best offer based on validity:
                                        </h4>
                                        <ul class="space-y-3 text-sm">
                                            <li
                                                v-for="insight in bestOffersByValidity(
                                                    activeSession.packages,
                                                )"
                                                :key="insight.label"
                                                class="flex items-center gap-3 text-gray-200"
                                            >
                                                <div
                                                    class="w-1.5 h-1.5 rounded-full bg-blue-400 shadow-[0_0_8px_rgba(96,165,250,0.8)]"
                                                ></div>
                                                <span
                                                    class="w-20 font-medium text-gray-400"
                                                    >{{ insight.label }}</span
                                                >
                                                <span class="text-gray-600"
                                                    >:</span
                                                >
                                                <span
                                                    class="font-bold text-white tracking-wide"
                                                    >{{ insight.gb }}GB
                                                    {{ insight.price }}K</span
                                                >
                                                <span
                                                    class="text-blue-300 bg-blue-900/30 px-2 py-0.5 rounded text-xs font-semibold border border-blue-800/50"
                                                    >({{
                                                        insight.provider
                                                    }})</span
                                                >
                                            </li>
                                            <li
                                                v-if="
                                                    bestOffersByValidity(
                                                        activeSession.packages,
                                                    ).length === 0
                                                "
                                                class="text-gray-500 italic text-xs"
                                            >
                                                Memproses data...
                                            </li>
                                        </ul>
                                    </div>

                                    <div>
                                        <h4
                                            class="text-xs font-semibold text-gray-400 uppercase tracking-wider mb-4 border-b border-gray-800 pb-2"
                                        >
                                            The best offer monthly pack:
                                        </h4>
                                        <ul class="space-y-3 text-sm">
                                            <li
                                                v-for="insight in bestOffersMonthly(
                                                    activeSession.packages,
                                                )"
                                                :key="insight.label"
                                                class="flex items-center gap-3 text-gray-200"
                                            >
                                                <div
                                                    class="w-1.5 h-1.5 rounded-full bg-purple-400 shadow-[0_0_8px_rgba(192,132,252,0.8)]"
                                                ></div>
                                                <span
                                                    class="w-24 font-medium text-gray-400"
                                                    >{{ insight.label }}</span
                                                >
                                                <span class="text-gray-600"
                                                    >:</span
                                                >
                                                <span
                                                    class="font-bold text-white tracking-wide"
                                                    >{{ insight.gb }}GB
                                                    {{ insight.price }}K</span
                                                >
                                                <span
                                                    class="text-purple-300 bg-purple-900/30 px-2 py-0.5 rounded text-xs font-semibold border border-purple-800/50"
                                                    >({{
                                                        insight.provider
                                                    }})</span
                                                >
                                            </li>
                                            <li
                                                v-if="
                                                    bestOffersMonthly(
                                                        activeSession.packages,
                                                    ).length === 0
                                                "
                                                class="text-gray-500 italic text-xs"
                                            >
                                                Memproses data...
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Extracted Data Table Widget -->
                        <div
                            v-if="
                                activeSession.packages &&
                                activeSession.packages.length > 0
                            "
                            class="bg-[#1e1e20] border border-gray-800 rounded-2xl overflow-hidden shadow-xl mb-8"
                        >
                            <div
                                class="px-6 py-4 border-b border-gray-800 bg-[#252528]/50 flex justify-between items-center"
                            >
                                <h3
                                    class="font-semibold text-gray-200 flex items-center gap-2"
                                >
                                    <div
                                        class="w-2 h-2 rounded-full bg-green-500"
                                    ></div>
                                    Data Berhasil Diekstrak ({{
                                        activeSession.packages.length
                                    }}
                                    paket)
                                </h3>
                                <div class="flex items-center gap-3">
                                    <div
                                        v-if="activeSession.performance_metrics"
                                        class="hidden md:flex gap-2 text-[10px] bg-black/30 rounded-lg px-2 py-1 border border-gray-700"
                                    >
                                        <span
                                            class="text-gray-400"
                                            title="Waktu Ekstraksi Vision AI"
                                            >⏱️ Ekstraksi:
                                            <span class="text-indigo-300"
                                                >{{
                                                    activeSession
                                                        .performance_metrics
                                                        .extract_time
                                                }}s</span
                                            ></span
                                        >
                                        <span class="text-gray-600">|</span>
                                        <span
                                            class="text-gray-400"
                                            title="Waktu Pembuatan Benchmarking"
                                            >Analisis:
                                            <span class="text-indigo-300"
                                                >{{
                                                    activeSession
                                                        .performance_metrics
                                                        .chat_time
                                                }}s</span
                                            ></span
                                        >
                                        <span class="text-gray-600">|</span>
                                        <span
                                            class="text-gray-400"
                                            title="Total Waktu"
                                            >Total:
                                            <span
                                                class="text-blue-400 font-bold"
                                                >{{
                                                    activeSession
                                                        .performance_metrics
                                                        .total_time
                                                }}s</span
                                            ></span
                                        >
                                    </div>
                                    <a
                                        :href="
                                            route(
                                                'scanner.export',
                                                activeSession.id,
                                            )
                                        "
                                        class="text-xs px-3 py-1.5 bg-green-700 hover:bg-green-600 rounded-md text-white transition border border-green-600 flex items-center gap-1 font-medium"
                                    >
                                        <svg
                                            class="w-3.5 h-3.5"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"
                                            ></path>
                                        </svg>
                                        Download Excel
                                    </a>
                                    <a
                                        :href="route('scanner.exportCsv', activeSession.id)"
                                        target="_blank"
                                        class="text-xs px-3 py-1.5 bg-indigo-700 hover:bg-indigo-600 rounded-md text-white transition border border-indigo-600 flex items-center gap-1 font-medium"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                                        </svg>
                                        Download CSV
                                    </a>
                                    <button
                                        @click="
                                            toggleEditTable(
                                                activeSession.id,
                                                activeSession.packages,
                                            )
                                        "
                                        class="text-xs px-3 py-1.5 bg-blue-700 hover:bg-blue-600 rounded-md text-white transition border border-blue-600 flex items-center gap-1 font-medium"
                                    >
                                        <svg
                                            class="w-3.5 h-3.5"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"
                                            ></path>
                                        </svg>
                                        {{
                                            isEditingTable[activeSession.id]
                                                ? "Batal Edit"
                                                : "Edit Data"
                                        }}
                                    </button>
                                    <button
                                        @click="toggleTable(activeSession.id)"
                                        class="text-xs px-3 py-1.5 bg-gray-800 hover:bg-gray-700 rounded-md text-gray-300 transition border border-gray-700"
                                    >
                                        {{
                                            activeTables[activeSession.id]
                                                ? "Sembunyikan Tabel"
                                                : "Lihat Tabel"
                                        }}
                                    </button>
                                </div>
                            </div>

                            <div
                                v-show="activeTables[activeSession.id]"
                                class="flex flex-col xl:flex-row border-t border-gray-800"
                            >
                                <!-- LEFT: Source Images (Only show when editing for easier crosscheck) -->
                                <div
                                    v-if="isEditingTable[activeSession.id]"
                                    class="w-full xl:w-1/3 p-4 bg-[#161618] border-b xl:border-b-0 xl:border-r border-gray-800 max-h-[600px] overflow-y-auto custom-scrollbar"
                                >
                                    <h4
                                        class="text-xs text-gray-400 font-semibold mb-4 uppercase tracking-wider sticky top-0 bg-[#161618] py-2"
                                    >
                                        Gambar Sumber Asli
                                    </h4>
                                    <div
                                        v-if="activeSession.chat_messages"
                                        class="flex flex-col gap-4"
                                    >
                                        <template
                                            v-for="msg in activeSession.chat_messages"
                                        >
                                            <div
                                                v-if="
                                                    msg.attachments &&
                                                    msg.attachments.length > 0
                                                "
                                                class="flex flex-col gap-4"
                                            >
                                                <template
                                                    v-for="att in msg.attachments"
                                                >
                                                    <img
                                                        v-if="
                                                            att.match(
                                                                /\.(jpeg|jpg|gif|png|webp)$/i,
                                                            )
                                                        "
                                                        :src="'/storage/' + att"
                                                        class="rounded-lg border border-gray-700 hover:border-blue-500 transition cursor-zoom-in w-full object-contain bg-black shadow-lg"
                                                        @click="openImage(att)"
                                                        title="Klik untuk memperbesar"
                                                    />
                                                    <div
                                                        v-else
                                                        class="text-xs text-gray-400 p-2 bg-gray-800 rounded border border-gray-700 break-all flex items-center gap-2"
                                                    >
                                                        <svg
                                                            class="w-4 h-4 shrink-0 text-blue-400"
                                                            fill="none"
                                                            stroke="currentColor"
                                                            viewBox="0 0 24 24"
                                                        >
                                                            <path
                                                                stroke-linecap="round"
                                                                stroke-linejoin="round"
                                                                stroke-width="2"
                                                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                                                            ></path>
                                                        </svg>
                                                        {{
                                                            att.split("/").pop()
                                                        }}
                                                    </div>
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                </div>

                                <!-- RIGHT: Table -->
                                <div
                                    class="w-full overflow-x-auto"
                                    :class="
                                        isEditingTable[activeSession.id]
                                            ? 'xl:w-2/3'
                                            : ''
                                    "
                                >
                                    <table
                                        class="w-full text-sm text-left text-gray-300"
                                    >
                                        <thead
                                            class="text-xs text-gray-400 uppercase bg-[#1a1a1c] border-b border-gray-800"
                                        >
                                            <tr>
                                                <th class="px-4 py-3">
                                                    Provider
                                                </th>
                                                <th class="px-4 py-3">
                                                    Nama Paket
                                                </th>
                                                <th
                                                    class="px-4 py-3 text-right"
                                                >
                                                    Kuota (GB)
                                                </th>
                                                <th
                                                    class="px-4 py-3 text-right"
                                                >
                                                    Masa Aktif
                                                </th>
                                                <th
                                                    class="px-4 py-3 text-right"
                                                >
                                                    Harga
                                                </th>
                                                <th
                                                    v-if="
                                                        !isEditingTable[
                                                            activeSession.id
                                                        ]
                                                    "
                                                    class="px-4 py-3"
                                                >
                                                    Kategori
                                                </th>
                                                <th
                                                    v-if="
                                                        !isEditingTable[
                                                            activeSession.id
                                                        ]
                                                    "
                                                    class="px-4 py-3 text-right"
                                                >
                                                    Yield (Rp/GB)
                                                </th>
                                                <th
                                                    v-if="
                                                        isEditingTable[
                                                            activeSession.id
                                                        ]
                                                    "
                                                    class="px-4 py-3 text-center"
                                                >
                                                    Aksi
                                                </th>
                                            </tr>
                                        </thead>

                                        <!-- View Mode -->
                                        <tbody
                                            v-if="
                                                !isEditingTable[
                                                    activeSession.id
                                                ]
                                            "
                                        >
                                            <tr
                                                v-for="pkg in activeSession.packages"
                                                :key="pkg.id"
                                                class="border-b border-gray-800 hover:bg-[#252528] transition-colors"
                                            >
                                                <td
                                                    class="px-4 py-3 font-medium text-gray-200"
                                                >
                                                    {{ pkg.provider }}
                                                </td>
                                                <td class="px-4 py-3 text-gray-300">
                                                    {{ pkg.package_name || '-' }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-right font-medium text-blue-400"
                                                >
                                                    {{ pkg.gb }} GB
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-right text-gray-400"
                                                >
                                                    {{ pkg.days }} Hari
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-right text-gray-300"
                                                >
                                                    Rp
                                                    {{
                                                        Number(
                                                            pkg.price,
                                                        ).toLocaleString(
                                                            "id-ID",
                                                        )
                                                    }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-gray-400"
                                                >
                                                    <span
                                                        class="px-2.5 py-1 bg-gray-800 rounded-full text-[10px] border border-gray-700"
                                                        >{{
                                                            pkg.category
                                                        }}</span
                                                    >
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-right font-bold text-xs"
                                                    :class="{
                                                        'text-green-400':
                                                            pkg.yield_val <
                                                            3000,
                                                        'text-yellow-400':
                                                            pkg.yield_val >=
                                                                3000 &&
                                                            pkg.yield_val <=
                                                                5000,
                                                        'text-red-400':
                                                            pkg.yield_val >
                                                            5000,
                                                    }"
                                                >
                                                    Rp
                                                    {{
                                                        Number(
                                                            pkg.yield_val,
                                                        ).toLocaleString(
                                                            "id-ID",
                                                        )
                                                    }}
                                                </td>
                                            </tr>
                                        </tbody>

                                        <!-- Edit Mode -->
                                        <tbody v-else>
                                            <tr
                                                v-for="(
                                                    pkg, idx
                                                ) in editablePackages[
                                                    activeSession.id
                                                ]"
                                                :key="idx"
                                                class="border-b border-gray-800 bg-[#1e1e20] hover:bg-[#252528]"
                                            >
                                                <td class="px-2 py-2">
                                                    <input
                                                        type="text"
                                                        v-model="pkg.provider"
                                                        class="w-full bg-[#131314] border border-gray-700 rounded px-2 py-1.5 text-xs text-white focus:border-blue-500 outline-none"
                                                    />
                                                </td>
                                                <td class="px-2 py-2">
                                                    <input
                                                        type="text"
                                                        v-model="pkg.package_name"
                                                        placeholder="Nama (opsional)"
                                                        class="w-full bg-[#131314] border border-gray-700 rounded px-2 py-1.5 text-xs text-white focus:border-blue-500 outline-none"
                                                    />
                                                </td>
                                                <td
                                                    class="px-2 py-2 text-right"
                                                >
                                                    <input
                                                        type="number"
                                                        step="0.1"
                                                        v-model="pkg.gb"
                                                        class="w-full max-w-[80px] bg-[#131314] border border-gray-700 rounded px-2 py-1.5 text-xs text-white focus:border-blue-500 outline-none text-right"
                                                    />
                                                </td>
                                                <td
                                                    class="px-2 py-2 text-right"
                                                >
                                                    <input
                                                        type="number"
                                                        v-model="pkg.days"
                                                        class="w-full max-w-[80px] bg-[#131314] border border-gray-700 rounded px-2 py-1.5 text-xs text-white focus:border-blue-500 outline-none text-right"
                                                    />
                                                </td>
                                                <td
                                                    class="px-2 py-2 text-right"
                                                >
                                                    <input
                                                        type="number"
                                                        v-model="pkg.price"
                                                        class="w-full max-w-[100px] bg-[#131314] border border-gray-700 rounded px-2 py-1.5 text-xs text-white focus:border-blue-500 outline-none text-right"
                                                    />
                                                </td>
                                                <td
                                                    class="px-2 py-2 text-center"
                                                >
                                                    <div
                                                        class="flex justify-center items-center gap-1.5"
                                                    >
                                                        <button
                                                            @click="
                                                                insertRowAfter(
                                                                    activeSession.id,
                                                                    idx,
                                                                )
                                                            "
                                                            class="text-green-400 hover:text-green-300 p-1.5 bg-green-900/30 hover:bg-green-800/50 rounded transition"
                                                            title="Sisipkan Baris di Bawah"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 6v6m0 0v6m0-6h6m-6 0H6"
                                                                ></path>
                                                            </svg>
                                                        </button>
                                                        <button
                                                            @click="
                                                                deleteRow(
                                                                    activeSession.id,
                                                                    idx,
                                                                )
                                                            "
                                                            class="text-red-400 hover:text-red-300 p-1.5 bg-red-900/30 hover:bg-red-800/50 rounded transition"
                                                            title="Hapus Baris"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M6 18L18 6M6 6l12 12"
                                                                ></path>
                                                            </svg>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td
                                                    colspan="5"
                                                    class="px-4 py-4 bg-[#161618]"
                                                >
                                                    <div
                                                        class="flex justify-between items-center"
                                                    >
                                                        <button
                                                            @click="
                                                                addEmptyRow(
                                                                    activeSession.id,
                                                                )
                                                            "
                                                            class="px-3 py-1.5 bg-gray-800 hover:bg-gray-700 border border-gray-600 rounded text-xs text-white transition flex items-center gap-1"
                                                        >
                                                            <svg
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 4v16m8-8H4"
                                                                ></path>
                                                            </svg>
                                                            Tambah Data Manual
                                                        </button>
                                                        <button
                                                            @click="
                                                                savePackages(
                                                                    activeSession.id,
                                                                )
                                                            "
                                                            class="px-4 py-1.5 bg-blue-600 hover:bg-blue-500 rounded text-sm text-white font-medium transition shadow-lg flex items-center gap-2"
                                                            :disabled="
                                                                savingTable[
                                                                    activeSession
                                                                        .id
                                                                ]
                                                            "
                                                        >
                                                            <svg
                                                                v-if="
                                                                    savingTable[
                                                                        activeSession
                                                                            .id
                                                                    ]
                                                                "
                                                                class="w-4 h-4 animate-spin"
                                                                fill="none"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <circle
                                                                    class="opacity-25"
                                                                    cx="12"
                                                                    cy="12"
                                                                    r="10"
                                                                    stroke="currentColor"
                                                                    stroke-width="4"
                                                                ></circle>
                                                                <path
                                                                    class="opacity-75"
                                                                    fill="currentColor"
                                                                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                                                ></path>
                                                            </svg>
                                                            <svg
                                                                v-else
                                                                class="w-4 h-4"
                                                                fill="none"
                                                                stroke="currentColor"
                                                                viewBox="0 0 24 24"
                                                            >
                                                                <path
                                                                    stroke-linecap="round"
                                                                    stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M5 13l4 4L19 7"
                                                                ></path>
                                                            </svg>
                                                            Simpan Perubahan
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                        <!-- Render Charts from Chat History in Dashboard -->
                        <div class="grid grid-cols-1 xl:grid-cols-2 gap-6">
                            <template
                                v-for="msg in activeSession.chat_messages"
                                :key="msg.id"
                            >
                                <div
                                    v-if="msg.chart_config"
                                    class="bg-[#1e1e20] p-6 rounded-2xl border border-gray-800 shadow-xl flex flex-col h-full"
                                >
                                    <div class="flex items-center justify-between mb-4">
                                        <div class="flex items-center gap-2">
                                            <svg
                                                class="w-5 h-5 text-indigo-400"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M7 12l3-3 3 3 4-4M8 21l4-4 4 4M3 4h18M4 4h16v12a1 1 0 01-1 1H5a1 1 0 01-1-1V4z"
                                                ></path>
                                            </svg>
                                            <h3 class="font-semibold text-gray-200">
                                                Visualisasi Data
                                            </h3>
                                        </div>
                                        <button @click="deleteChart(activeSession, msg)" class="text-gray-500 hover:text-red-500 transition-colors p-1 rounded-full hover:bg-gray-700" title="Hapus Grafik">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                    </div>
                                    <ChartViewer
                                        :config="msg.chart_config"
                                        class="flex-1 w-full"
                                    />
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <!-- FLOATING CHAT WIDGET -->
            <div
                class="absolute bottom-6 right-6 z-50 flex flex-col items-end pointer-events-none"
            >
                <!-- Chat Window Pop-up -->
                <div
                    v-show="isChatOpen"
                    class="pointer-events-auto bg-[#1e1e20] w-[400px] max-w-[calc(100vw-3rem)] h-[600px] max-h-[calc(100vh-8rem)] rounded-2xl shadow-[0_10px_50px_rgba(0,0,0,0.5)] border border-gray-700 flex flex-col mb-4 overflow-hidden origin-bottom-right transition-all"
                >
                    <!-- Chat Header -->
                    <div
                        class="bg-gradient-to-r from-blue-600 to-indigo-700 px-4 py-3.5 flex justify-between items-center shadow-md z-10"
                    >
                        <div class="flex items-center gap-3">
                            <div
                                class="w-8 h-8 rounded-full bg-white/20 flex items-center justify-center backdrop-blur-sm"
                            >
                                <svg
                                    class="w-4 h-4 text-white"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"
                                    ></path>
                                </svg>
                            </div>
                            <div>
                                <h3
                                    class="font-bold text-white text-sm leading-tight"
                                >
                                    AI Assistant
                                </h3>
                                <p class="text-[10px] text-blue-100 opacity-80">
                                    {{
                                        activeSession
                                            ? "Online"
                                            : "Silakan pilih sesi"
                                    }}
                                </p>
                            </div>
                        </div>
                        <button
                            @click="isChatOpen = false"
                            class="p-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-white transition backdrop-blur-sm"
                        >
                            <svg
                                class="w-4 h-4"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                ></path>
                            </svg>
                        </button>
                    </div>

                    <!-- Chat Body -->
                    <div
                        ref="chatContainer"
                        class="flex-1 overflow-y-auto p-4 space-y-4 custom-scrollbar bg-[#131314]"
                    >
                        <div
                            v-if="
                                !activeSession ||
                                activeSession.chat_messages?.length === 0
                            "
                            class="h-full flex flex-col items-center justify-center text-center"
                        >
                            <div
                                class="w-16 h-16 bg-[#1e1e20] rounded-full flex items-center justify-center mb-4 shadow-inner"
                            >
                                <svg
                                    class="w-8 h-8 text-gray-500"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                                    ></path>
                                </svg>
                            </div>
                            <p class="text-sm font-medium text-gray-300">
                                Belum ada percakapan
                            </p>
                            <p class="text-xs text-gray-500 mt-1 max-w-[200px]">
                                Mulai obrolan atau unggah pricelist untuk
                                dianalisis oleh AI.
                            </p>
                        </div>

                        <template v-if="activeSession">
                            <div
                                v-for="msg in activeSession.chat_messages"
                                :key="msg.id"
                                class="flex gap-2 text-sm"
                                :class="
                                    msg.role === 'user'
                                        ? 'flex-row-reverse'
                                        : ''
                                "
                            >
                                <!-- Avatar -->
                                <div
                                    class="w-7 h-7 shrink-0 rounded-full flex items-center justify-center mt-1 shadow-sm"
                                    :class="
                                        msg.role === 'user'
                                            ? 'bg-indigo-600'
                                            : 'bg-gradient-to-br from-blue-500 to-purple-500'
                                    "
                                >
                                    <svg
                                        v-if="msg.role === 'user'"
                                        class="w-4 h-4 text-white"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"
                                        ></path>
                                    </svg>
                                    <svg
                                        v-else
                                        class="w-4 h-4 text-white"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M13 10V3L4 14h7v7l9-11h-7z"
                                        ></path>
                                    </svg>
                                </div>

                                <!-- Content Bubble -->
                                <div
                                    class="max-w-[82%] rounded-2xl p-3"
                                    :class="
                                        msg.role === 'user'
                                            ? 'bg-indigo-600 text-white rounded-tr-sm shadow-md'
                                            : 'bg-[#1e1e20] text-gray-200 rounded-tl-sm border border-gray-800 shadow-sm'
                                    "
                                >
                                    <div
                                        v-if="
                                            msg.attachments &&
                                            msg.attachments.length > 0
                                        "
                                        class="flex flex-col gap-1 mb-2"
                                    >
                                        <div
                                            v-for="attachment in msg.attachments"
                                            :key="attachment"
                                            class="flex items-center gap-2 bg-black/20 rounded-lg px-2.5 py-1.5 text-xs border border-white/10"
                                        >
                                            <svg
                                                class="w-3.5 h-3.5 text-blue-300"
                                                fill="none"
                                                stroke="currentColor"
                                                viewBox="0 0 24 24"
                                            >
                                                <path
                                                    stroke-linecap="round"
                                                    stroke-linejoin="round"
                                                    stroke-width="2"
                                                    d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                                                ></path>
                                            </svg>
                                            <span class="truncate">{{
                                                attachment.split("/").pop()
                                            }}</span>
                                        </div>
                                    </div>

                                    <!-- Inform user that chart is on dashboard -->
                                    <div
                                        v-if="msg.chart_config"
                                        class="text-xs bg-blue-900/20 text-blue-300 border border-blue-900/50 rounded-lg px-3 py-2 mb-2 flex items-center gap-2"
                                    >
                                        <svg
                                            class="w-4 h-4 shrink-0"
                                            fill="none"
                                            stroke="currentColor"
                                            viewBox="0 0 24 24"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                                            ></path>
                                        </svg>
                                        Grafik telah ditambahkan ke Dashboard
                                        Utama.
                                    </div>

                                    <div
                                        class="prose prose-sm prose-invert max-w-none text-[13px] leading-relaxed break-words font-medium"
                                        v-html="parseMarkdown(msg.content)"
                                    ></div>
                                </div>
                            </div>

                            <!-- Typing indicator -->
                            <div
                                v-if="chatLoading[activeSession.id]"
                                class="flex gap-2"
                            >
                                <div
                                    class="w-7 h-7 shrink-0 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center mt-1"
                                >
                                    <svg
                                        class="w-4 h-4 text-white"
                                        fill="none"
                                        stroke="currentColor"
                                        viewBox="0 0 24 24"
                                    >
                                        <path
                                            stroke-linecap="round"
                                            stroke-linejoin="round"
                                            stroke-width="2"
                                            d="M13 10V3L4 14h7v7l9-11h-7z"
                                        ></path>
                                    </svg>
                                </div>
                                <div
                                    class="bg-[#1e1e20] border border-gray-800 rounded-2xl rounded-tl-sm p-3.5 flex space-x-1.5 items-center w-fit shadow-sm"
                                >
                                    <div
                                        class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                                    ></div>
                                    <div
                                        class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                                        style="animation-delay: 0.15s"
                                    ></div>
                                    <div
                                        class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                                        style="animation-delay: 0.3s"
                                    ></div>
                                </div>
                            </div>
                        </template>
                    </div>

            <!-- Global Error Toast -->
            <transition
                enter-active-class="transition ease-out duration-300"
                enter-from-class="transform opacity-0 -translate-y-4"
                enter-to-class="transform opacity-100 translate-y-0"
                leave-active-class="transition ease-in duration-200"
                leave-from-class="transform opacity-100 translate-y-0"
                leave-to-class="transform opacity-0 -translate-y-4"
            >
                <div v-if="globalErrorMsg" class="fixed top-4 right-4 z-50 flex items-center p-4 mb-4 w-full max-w-xs text-white bg-red-600 rounded-lg shadow-lg border border-red-500" role="alert">
                    <div class="inline-flex flex-shrink-0 justify-center items-center w-8 h-8 text-red-500 bg-red-100 rounded-lg">
                        <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 11.793a1 1 0 1 1-1.414 1.414L10 11.414l-2.293 2.293a1 1 0 0 1-1.414-1.414L8.586 10 6.293 7.707a1 1 0 0 1 1.414-1.414L10 8.586l2.293-2.293a1 1 0 0 1 1.414 1.414L11.414 10l2.293 2.293Z"/>
                        </svg>
                        <span class="sr-only">Error icon</span>
                    </div>
                    <div class="ms-3 text-sm font-normal">{{ globalErrorMsg }}</div>
                    <button @click="globalErrorMsg = ''" type="button" class="ms-auto -mx-1.5 -my-1.5 bg-red-600 text-red-200 hover:text-white rounded-lg focus:ring-2 focus:ring-red-400 p-1.5 hover:bg-red-700 inline-flex items-center justify-center h-8 w-8" aria-label="Close">
                        <span class="sr-only">Close</span>
                        <svg class="w-3 h-3" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 14 14">
                            <path stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m1 1 6 6m0 0 6 6M7 7l6-6M7 7l-6 6"/>
                        </svg>
                    </button>
                </div>
            </transition>

            <!-- Main Content Area -->
                    <!-- Chat Input Area -->
                    <div
                        class="p-3 bg-[#1e1e20] border-t border-gray-800 shrink-0 relative"
                    >
                        <div
                            v-if="form.images.length > 0"
                            class="absolute -top-10 left-3 right-3 bg-indigo-900/90 backdrop-blur text-indigo-100 text-xs px-3 py-2 rounded-t-lg flex items-center justify-between border-t border-x border-indigo-700/50 shadow-lg"
                        >
                            <span class="font-medium flex items-center gap-1.5">
                                <svg
                                    class="w-3.5 h-3.5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"
                                    ></path>
                                </svg>
                                {{ form.images.length }} file siap dikirim
                            </span>
                            <button
                                type="button"
                                @click="form.images = []"
                                class="p-1 hover:bg-white/20 rounded"
                            >
                                <svg
                                    class="w-3 h-3"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M6 18L18 6M6 6l12 12"
                                    ></path>
                                </svg>
                            </button>
                        </div>
                        <form
                            @submit.prevent="submit"
                            class="flex items-end gap-2 bg-[#131314] p-1.5 border border-gray-700 rounded-xl focus-within:border-indigo-500 transition-colors shadow-inner"
                        >
                            <label
                                class="cursor-pointer p-2 text-gray-400 hover:text-indigo-400 hover:bg-[#1e1e20] rounded-lg transition shrink-0"
                                title="Unggah Gambar / PDF"
                            >
                                <svg
                                    class="w-5 h-5"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"
                                    ></path>
                                </svg>
                                <input
                                    type="file"
                                    ref="fileInput"
                                    accept="image/*,.pdf,.zip"
                                    multiple
                                    @change="
                                        (e) =>
                                            (form.images = Array.from(
                                                e.target.files,
                                            ))
                                    "
                                    class="hidden"
                                />
                            </label>

                            <textarea
                                v-model="form.message"
                                placeholder="Ketik pesan..."
                                class="flex-1 bg-transparent border-none text-white text-[13px] focus:ring-0 p-2 max-h-24 min-h-[40px] resize-none outline-none custom-scrollbar"
                                @keydown.enter.prevent="submit"
                                rows="1"
                            ></textarea>

                            <button
                                type="submit"
                                :disabled="
                                    form.processing ||
                                    (form.images.length === 0 &&
                                        !form.message.trim())
                                "
                                class="p-2.5 text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shrink-0 disabled:opacity-40 disabled:bg-gray-700 transition shadow-sm"
                            >
                                <svg
                                    class="w-4 h-4"
                                    fill="none"
                                    stroke="currentColor"
                                    viewBox="0 0 24 24"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"
                                    ></path>
                                </svg>
                            </button>
                        </form>
                        <div class="text-center text-[10px] text-gray-500 mt-2">
                            SmartScan AI dapat membuat kesalahan.
                        </div>
                    </div>
                </div>

                <!-- Floating Toggle Button -->
                <button
                    @click="isChatOpen = !isChatOpen"
                    class="pointer-events-auto w-14 h-14 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-full flex items-center justify-center text-white shadow-[0_10px_25px_rgba(79,70,229,0.5)] hover:scale-105 hover:shadow-[0_10px_35px_rgba(79,70,229,0.7)] transition-all relative"
                >
                    <span
                        v-if="
                            !isChatOpen &&
                            activeSession &&
                            (activeSession.status === 'processing' ||
                                chatLoading[activeSession.id])
                        "
                        class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full border-2 border-[#131314] animate-ping"
                    ></span>
                    <span
                        v-if="
                            !isChatOpen &&
                            activeSession &&
                            (activeSession.status === 'processing' ||
                                chatLoading[activeSession.id])
                        "
                        class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full border-2 border-[#131314]"
                    ></span>

                    <svg
                        v-if="!isChatOpen"
                        class="w-6 h-6"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
                        ></path>
                    </svg>
                    <svg
                        v-else
                        class="w-6 h-6"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                    >
                        <path
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            stroke-width="2"
                            d="M6 18L18 6M6 6l12 12"
                        ></path>
                    </svg>
                </button>
            </div>
        </div>

        <!-- VLR Checker Modal -->
        <div v-if="isVlrModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm p-4">
            <div class="bg-[#1e1e20] border border-gray-700 rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-800 flex justify-between items-center">
                    <h3 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400 flex items-center gap-2">
                        <svg class="w-6 h-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"></path>
                        </svg>
                        VLR Checker (Cek Umur Kartu)
                    </h3>
                    <button @click="isVlrModalOpen = false" class="text-gray-400 hover:text-white transition bg-gray-800 hover:bg-gray-700 p-2 rounded-full">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="p-6 overflow-y-auto custom-scrollbar flex flex-col md:flex-row gap-6">
                    <!-- Input Section -->
                    <div class="w-full md:w-1/3 flex flex-col">
                        <label class="block text-sm font-medium text-gray-300 mb-2">Daftar Nomor Telepon (Satu per baris)</label>
                        <textarea 
                            v-model="vlrPhoneNumbers"
                            rows="10"
                            class="w-full bg-[#131314] border border-gray-700 text-gray-200 focus:border-blue-500 focus:ring-blue-500 rounded-xl shadow-inner mb-4 p-3 text-sm font-mono placeholder-gray-600 custom-scrollbar"
                            placeholder="Contoh:&#10;081234567890&#10;081987654321&#10;6285212341234"
                            :disabled="isVlrChecking"
                        ></textarea>
                        
                        <div v-if="vlrErrorMessage" class="text-red-400 text-sm mb-4 bg-red-900/20 p-3 rounded-lg border border-red-800/50">
                            {{ vlrErrorMessage }}
                        </div>
                        
                        <button 
                            @click="checkVlrNumbers"
                            :disabled="isVlrChecking"
                            class="w-full px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-medium rounded-xl disabled:opacity-50 transition shadow-lg flex items-center justify-center gap-2"
                        >
                            <svg v-if="isVlrChecking" class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                            <span>{{ isVlrChecking ? 'Sedang Mengecek...' : 'Mulai Cek VLR' }}</span>
                        </button>
                    </div>
                    
                    <!-- Results Section -->
                    <div class="w-full md:w-2/3 flex flex-col">
                        <label class="block text-sm font-medium text-gray-300 mb-2">Hasil Pengecekan</label>
                        <div class="bg-[#131314] border border-gray-700 rounded-xl overflow-hidden flex-1 flex flex-col min-h-[300px]">
                            <div class="overflow-x-auto overflow-y-auto custom-scrollbar flex-1">
                                <table class="min-w-full divide-y divide-gray-800 text-sm text-left">
                                    <thead class="bg-[#1a1a1c] sticky top-0">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 font-medium text-gray-400">No. Telepon</th>
                                            <th scope="col" class="px-4 py-3 font-medium text-gray-400">Provider</th>
                                            <th scope="col" class="px-4 py-3 font-medium text-gray-400">Umur (Hari)</th>
                                            <th scope="col" class="px-4 py-3 font-medium text-gray-400">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-800/50">
                                        <tr v-if="vlrResults.length === 0">
                                            <td colspan="4" class="px-4 py-12 text-center text-gray-500 italic">
                                                Belum ada data. Masukkan nomor telepon dan klik cek.
                                            </td>
                                        </tr>
                                        <tr v-for="(res, index) in vlrResults" :key="index" class="hover:bg-[#1a1a1c]/50 transition">
                                            <td class="px-4 py-3 font-mono text-gray-300">
                                                {{ res.number }}
                                            </td>
                                            <td class="px-4 py-3 text-gray-400">
                                                {{ res.provider }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="font-medium" :class="{'text-white': res.age !== '-', 'text-gray-600': res.age === '-'}">{{ res.age }}</span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span v-if="res.status === 'error'" class="px-2.5 py-1 inline-flex text-xs font-semibold rounded-full bg-red-900/30 text-red-400 border border-red-800/50">
                                                    {{ res.type }}
                                                </span>
                                                <span v-else-if="res.age < 90" class="px-2.5 py-1 inline-flex text-xs font-semibold rounded-full bg-green-900/30 text-green-400 border border-green-800/50">
                                                    {{ res.type || 'Babycare' }}
                                                </span>
                                                <span v-else class="px-2.5 py-1 inline-flex text-xs font-semibold rounded-full bg-blue-900/30 text-blue-400 border border-blue-800/50">
                                                    {{ res.type || 'Non-Babycare' }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 6px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background-color: #3f3f46;
    border-radius: 20px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background-color: #52525b;
}

/* Tailwind typography overrides for dark mode */
:deep(.prose-invert) {
    color: #e4e4e7;
}
:deep(.prose-invert h1),
:deep(.prose-invert h2),
:deep(.prose-invert h3),
:deep(.prose-invert h4) {
    color: #f4f4f5;
}
:deep(.prose-invert a) {
    color: #60a5fa;
}
:deep(.prose-invert strong) {
    color: #f4f4f5;
}
:deep(.prose-invert code) {
    color: #fb7185;
    background-color: #27272a;
    padding: 0.125rem 0.25rem;
    border-radius: 0.25rem;
}
</style>
