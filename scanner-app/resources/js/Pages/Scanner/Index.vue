<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, onMounted, onUnmounted, nextTick, computed } from "vue";
import axios from "axios";
import ChartViewer from "@/Components/ChartViewer.vue";
import { marked } from "marked";

const props = defineProps({
    pricelists: Array,
});

// ─── State ────────────────────────────────────────────────────────
const form = useForm({ message: '', images: [] });
const fileInput = ref(null);
const chatContainer = ref(null);
const sidebarOpen = ref(true);
const sidebarTab = ref("history"); // 'history' | 'keys' | 'models'

const activeSessionId = ref(null);

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
        pricelist_id: activeSessionId.value
    })).post(route("scanner.store"), {
        preserveScroll: true,
        onSuccess: (page) => {
            form.reset();
            if (fileInput.value) fileInput.value.value = "";
            if (!activeSessionId.value && page.props.pricelists.length > 0) {
                const newest = [...page.props.pricelists].sort((a,b) => new Date(b.created_at) - new Date(a.created_at))[0];
                activeSessionId.value = newest.id;
            }
            scrollToBottom();
        },
    });
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
        alert(e.response?.data?.error || "Gagal mengirim pesan.");
    }

    chatLoading.value[pricelist.id] = false;
};

const deleteSession = (id) => {
    if (!confirm("Yakin hapus sesi ini beserta semua data dan gambarnya?")) return;
    router.delete(route("scanner.destroy", id), {
        onSuccess: () => {
            if (activeSessionId.value === id) {
                activeSessionId.value = null;
            }
        }
    });
};

const editingPrompt = ref({});
const retryLoading = ref({});

const retryScan = async (id) => {
    if (!confirm("Ulangi proses scan untuk sesi ini?")) return;
    retryLoading.value[id] = true;
    try {
        await axios.post(route("scanner.retry", id));
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.error || "Gagal mengulangi scan.");
    }
    retryLoading.value[id] = false;
};

const openEditPrompt = (list) => {
    const firstMsg = list.chat_messages?.find(m => m.attachments && m.attachments.length > 0);
    editingPrompt.value[list.id] = {
        messageId: firstMsg ? firstMsg.id : null,
        content: firstMsg ? firstMsg.content : "Tolong scan gambar ini.",
        show: true
    };
};

const savePromptAndRetry = async (list) => {
    const editData = editingPrompt.value[list.id];
    if (!editData || !editData.messageId) {
        alert("Tidak dapat menemukan pesan awal untuk diedit.");
        return;
    }

    retryLoading.value[list.id] = true;
    try {
        await axios.put(route("scanner.message.update", { pricelist: list.id, chatMessage: editData.messageId }), {
            content: editData.content
        });
        
        await axios.post(route("scanner.retry", list.id));
        
        editingPrompt.value[list.id].show = false;
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.error || "Gagal menyimpan prompt & mengulangi scan.");
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
            filename: renameTitle.value
        });
        renameSessionId.value = null;
        router.reload({ only: ["pricelists"], preserveScroll: true });
    } catch (e) {
        alert(e.response?.data?.error || "Gagal mengubah nama.");
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
        alert(e.response?.data?.message || "Failed to add key");
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
let interval = null;
onMounted(() => {
    scrollToBottom();
    fetchKeys();
    interval = setInterval(() => {
        const hasPending = props.pricelists.some(
            (l) => l.status === "pending" || l.status === "processing",
        );
        if (hasPending)
            router.reload({ only: ["pricelists"], preserveScroll: true });
    }, 5000);
});
onUnmounted(() => {
    clearInterval(interval);
});
</script>


<template>
    <Head title="Gemini Scanner" />
    <div class="h-screen flex bg-[#131314] text-gray-100 font-sans overflow-hidden">
        
        <!-- SIDEBAR -->
        <div 
            :class="sidebarOpen ? 'w-72' : 'w-0 opacity-0'" 
            class="flex-shrink-0 bg-[#1e1e20] flex flex-col transition-all duration-300 overflow-hidden border-r border-gray-800"
        >
            <!-- Sidebar Header & New Chat -->
            <div class="p-4 border-b border-gray-800 flex items-center justify-between">
                <h2 class="text-lg font-semibold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400">Gemini Scanner</h2>
                <button @click="sidebarOpen = false" class="p-1 hover:bg-gray-700 rounded-lg transition text-gray-400">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path></svg>
                </button>
            </div>
            <!-- Status & Input API Key -->
            <div class="p-3 border-b border-gray-800 space-y-4">
                <!-- Status Usage -->
                <div class="bg-[#2a2a2c] rounded-xl p-3 border border-gray-700">
                    <div class="text-xs text-gray-400 mb-1">Kapasitas Model</div>
                    <div class="flex items-end justify-between">
                        <div>
                            <div class="text-xl font-bold text-blue-400">{{ Math.max(0, (activeKeyCount * 1500) - totalUsage) }}</div>
                            <div class="text-[10px] text-gray-500">Permintaan tersisa</div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-gray-300">{{ activeKeyCount }} Key</div>
                            <div class="text-[10px] text-gray-500">Aktif</div>
                        </div>
                    </div>
                    <!-- Progress Bar -->
                    <div class="w-full bg-gray-800 rounded-full h-1.5 mt-2 overflow-hidden" title="Persentase Penggunaan">
                        <div class="bg-gradient-to-r from-blue-500 to-indigo-500 h-1.5 rounded-full transition-all duration-500" :style="`width: ${Math.min(100, (totalUsage / Math.max(1, activeKeyCount * 1500)) * 100)}%`"></div>
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
                    >
                    <button type="submit" :disabled="keyLoading" class="bg-[#2a2a2c] hover:bg-[#353538] border border-gray-700 text-gray-300 rounded-lg px-3 py-2 transition flex items-center justify-center shrink-0 disabled:opacity-50">
                        <svg v-if="keyLoading" class="w-4 h-4 animate-spin text-blue-400" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                        <svg v-else class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    </button>
                </form>

                <button @click="newChat" class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-[#2a2a2c] hover:bg-[#353538] text-sm font-medium rounded-lg transition border border-gray-700">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                    Percakapan Baru
                </button>
            </div>

            <!-- History List -->
            <div class="flex-1 overflow-y-auto px-3 pb-4 space-y-1 custom-scrollbar">
                <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2 mt-4 px-2">Terbaru</div>
                
                <div v-for="list in sortedPricelists" :key="list.id" 
                    class="group flex items-center justify-between p-2 rounded-lg cursor-pointer transition text-sm"
                    :class="activeSessionId === list.id ? 'bg-[#2a2a2c] text-white' : 'text-gray-300 hover:bg-[#202022] hover:text-white'"
                    @click="activeSessionId = list.id"
                >
                    <div class="flex items-center gap-3 overflow-hidden flex-1">
                        <svg class="w-4 h-4 text-gray-500 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                        
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

                    <div v-if="renameSessionId !== list.id" class="flex items-center opacity-0 group-hover:opacity-100 transition shrink-0 ml-2">
                        <button @click.stop="startRename(list)" class="p-1 hover:bg-gray-600 rounded text-gray-400 hover:text-white" title="Rename">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                        </button>
                        <button @click.stop="deleteSession(list.id)" class="p-1 hover:bg-red-900/50 rounded text-gray-400 hover:text-red-400" title="Delete">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="p-4 border-t border-gray-800 text-xs text-gray-500 flex justify-between items-center">
                <span>API Keys: {{ activeKeyCount }} active</span>
                <span title="Total Usage">{{ totalUsage }} reqs</span>
            </div>
        </div>

        <!-- MAIN AREA -->
        <div class="flex-1 flex flex-col h-screen relative">
            
            <!-- Topbar (Mobile Hamburger) -->
            <div class="h-14 flex items-center px-4 border-b border-gray-800 shrink-0">
                <button v-if="!sidebarOpen" @click="sidebarOpen = true" class="p-2 hover:bg-gray-800 rounded-lg text-gray-400 transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
                </button>
                <div class="ml-auto flex items-center gap-2">
                     <span v-if="activeSession" class="text-sm text-gray-400">{{ activeSession.filename }}</span>
                </div>
            </div>

            <!-- CHAT AREA -->
            <div ref="chatContainer" class="flex-1 overflow-y-auto px-4 py-6 scroll-smooth custom-scrollbar">
                <div class="max-w-4xl mx-auto space-y-8 pb-32">
                    
                    <!-- EMPTY STATE -->
                    <div v-if="!activeSession" class="h-full flex flex-col items-center justify-center text-center mt-32">
                        <div class="w-16 h-16 bg-gradient-to-tr from-blue-500 to-purple-500 rounded-full flex items-center justify-center mb-6 shadow-lg shadow-purple-500/20">
                            <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                        </div>
                        <h1 class="text-3xl font-bold mb-2">Gemini Pricelist Scanner</h1>
                        <p class="text-gray-400 max-w-md">Unggah gambar brosur harga provider dan biarkan AI mengekstrak serta menganalisis kompetisi harga secara otomatis.</p>
                    </div>

                    <!-- ACTIVE SESSION -->
                    <template v-else>
                        <!-- Extracted Data Table Widget -->
                        <div v-if="activeSession.packages && activeSession.packages.length > 0" class="bg-[#1e1e20] border border-gray-700 rounded-xl overflow-hidden shadow-lg mb-8">
                            <div class="px-4 py-3 border-b border-gray-700 bg-[#252528] flex justify-between items-center">
                                <h3 class="font-medium text-gray-200 flex items-center gap-2">
                                    <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                                    Data Berhasil Diekstrak ({{ activeSession.packages.length }} paket)
                                </h3>
                                <button @click="toggleTable(activeSession.id)" class="text-xs px-3 py-1 bg-gray-700 hover:bg-gray-600 rounded text-white transition">
                                    {{ activeTables[activeSession.id] ? 'Sembunyikan Tabel' : 'Lihat Tabel' }}
                                </button>
                            </div>
                            
                            <div v-show="activeTables[activeSession.id]" class="overflow-x-auto">
                                <table class="w-full text-sm text-left text-gray-300">
                                    <thead class="text-xs text-gray-400 uppercase bg-[#252528] border-b border-gray-700">
                                        <tr>
                                            <th class="px-4 py-3">Provider</th>
                                            <th class="px-4 py-3">Kategori</th>
                                            <th class="px-4 py-3 text-right">Kuota (GB)</th>
                                            <th class="px-4 py-3 text-right">Masa Aktif</th>
                                            <th class="px-4 py-3 text-right">Harga</th>
                                            <th class="px-4 py-3 text-right">Yield (Rp/GB)</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="pkg in activeSession.packages" :key="pkg.id" class="border-b border-gray-800 hover:bg-[#2a2a2c]">
                                            <td class="px-4 py-3 font-medium text-gray-200">{{ pkg.provider }}</td>
                                            <td class="px-4 py-3 text-gray-400">{{ pkg.category }}</td>
                                            <td class="px-4 py-3 text-right">{{ pkg.gb }}</td>
                                            <td class="px-4 py-3 text-right">{{ pkg.days }}</td>
                                            <td class="px-4 py-3 text-right">Rp {{ Number(pkg.price).toLocaleString("id-ID") }}</td>
                                            <td class="px-4 py-3 text-right font-semibold"
                                                :class="{
                                                    'text-green-400': pkg.yield_val < 3000,
                                                    'text-yellow-400': pkg.yield_val >= 3000 && pkg.yield_val <= 5000,
                                                    'text-red-400': pkg.yield_val > 5000,
                                                }"
                                            >Rp {{ Number(pkg.yield_val).toLocaleString("id-ID") }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Chat Messages -->
                        <div v-for="msg in activeSession.chat_messages" :key="msg.id" class="flex gap-4">
                            <!-- Avatar -->
                            <div class="w-8 h-8 shrink-0 rounded-full flex items-center justify-center mt-1" 
                                :class="msg.role === 'user' ? 'bg-indigo-600' : 'bg-gradient-to-br from-blue-500 to-purple-500'">
                                <svg v-if="msg.role === 'user'" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
                                <svg v-else class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            </div>
                            
                            <!-- Content -->
                            <div class="flex-1 min-w-0">
                                <div class="font-medium text-gray-300 mb-1 text-sm">{{ msg.role === 'user' ? 'Anda' : 'Gemini' }}</div>
                                
                                <div v-if="msg.attachments && msg.attachments.length > 0" class="flex flex-wrap gap-2 mb-3">
                                    <div v-for="attachment in msg.attachments" :key="attachment" class="flex items-center gap-2 bg-[#1e1e20] border border-gray-700 rounded-lg px-3 py-2 text-sm text-gray-300">
                                        <svg class="w-4 h-4 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
                                        {{ attachment.split('/').pop() }}
                                    </div>
                                </div>
                                
                                <div class="prose prose-sm prose-invert max-w-none text-gray-200" v-html="parseMarkdown(msg.content)"></div>
                                
                                <div v-if="msg.chart_config" class="mt-4 bg-[#1e1e20] p-4 rounded-xl border border-gray-700 w-full max-w-3xl">
                                    <ChartViewer :config="msg.chart_config" />
                                </div>
                            </div>
                        </div>

                        <!-- Custom Status / Loading Indicator -->
                        <div v-if="activeSession.status !== 'processed' && activeSession.status !== 'failed'" class="flex gap-4">
                            <div class="w-8 h-8 shrink-0 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center mt-1">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            </div>
                            <div class="flex-1 min-w-0 bg-[#1e1e20] p-4 rounded-xl border border-blue-500/30 inline-block animate-pulse w-fit">
                                <div class="flex items-center gap-3">
                                    <div class="w-4 h-4 border-2 border-blue-400 border-t-transparent rounded-full animate-spin"></div>
                                    <span class="text-sm font-medium text-blue-300">{{ activeSession.status === 'pending' ? 'Menunggu antrean...' : activeSession.status }}</span>
                                </div>
                            </div>
                        </div>

                        <!-- Typing Indicator -->
                        <div v-if="chatLoading[activeSession.id]" class="flex gap-4">
                            <div class="w-8 h-8 shrink-0 rounded-full bg-gradient-to-br from-blue-500 to-purple-500 flex items-center justify-center mt-1">
                                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                            </div>
                            <div class="flex-1 flex items-center">
                                <div class="flex space-x-1.5 mt-2">
                                    <div class="w-2 h-2 bg-gray-500 rounded-full animate-bounce"></div>
                                    <div class="w-2 h-2 bg-gray-500 rounded-full animate-bounce" style="animation-delay: 0.15s"></div>
                                    <div class="w-2 h-2 bg-gray-500 rounded-full animate-bounce" style="animation-delay: 0.3s"></div>
                                </div>
                            </div>
                        </div>

                        <!-- Error State Processing -->
                        <div v-if="activeSession.status === 'failed'" class="mt-4 bg-red-900/20 border border-red-800 rounded-lg p-4 flex items-start gap-3">
                            <svg class="w-5 h-5 text-red-500 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            <div class="flex-1">
                                <h4 class="text-sm font-semibold text-red-400 mb-1">Gagal Memproses Gambar</h4>
                                <p class="text-xs text-red-300 mb-3">{{ activeSession.error_message }}</p>
                                <div class="flex gap-2">
                                    <button @click="retryScan(activeSession.id)" class="px-3 py-1.5 bg-red-600 hover:bg-red-700 text-white text-xs rounded transition flex items-center gap-1" :disabled="retryLoading[activeSession.id]">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                                        {{ retryLoading[activeSession.id] ? 'Mengulangi...' : 'Ulangi Scan' }}
                                    </button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- FLOATING INPUT BAR -->
            <div class="absolute bottom-0 left-0 right-0 p-4 bg-gradient-to-t from-[#131314] via-[#131314] to-transparent pt-10">
                <div class="max-w-4xl mx-auto">
                    <form @submit.prevent="submit" class="relative flex items-center bg-[#1e1e20] border border-gray-700 rounded-full shadow-lg shadow-black/20 focus-within:border-gray-500 transition-colors">
                        <label class="cursor-pointer p-3 ml-1 text-gray-400 hover:text-white transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"></path></svg>
                            <input type="file" ref="fileInput" accept="image/*,.pdf,.zip" multiple @change="(e) => (form.images = Array.from(e.target.files))" class="hidden" />
                        </label>
                        
                        <input type="text" v-model="form.message" placeholder="Tanyakan sesuatu atau unggah pricelist..." class="flex-1 bg-transparent border-none text-white text-sm focus:ring-0 px-2 py-4 outline-none placeholder-gray-500" />
                        
                        <button type="submit" :disabled="form.processing || (form.images.length === 0 && !form.message.trim())" class="p-3 mr-2 text-gray-400 hover:text-white disabled:opacity-30 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path></svg>
                        </button>
                    </form>
                    
                    <div v-if="form.images.length > 0" class="mt-2 text-xs text-indigo-400 text-center">
                        {{ form.images.length }} file(s) selected
                    </div>
                </div>
                <div class="text-center text-[10px] text-gray-600 mt-3 pb-1">
                    Gemini dapat membuat kesalahan. Periksa info penting.
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
