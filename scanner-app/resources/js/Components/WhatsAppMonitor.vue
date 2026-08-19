<script setup>
import { ref, onMounted, onUnmounted } from 'vue';
import axios from 'axios';

const stats = ref({
  total_conversations: 0,
  active_today: 0,
  messages_today: 0,
  images_processed: 0,
  connection: {
    driver: 'meta_cloud',
    connected: false,
    configured: false,
  }
});

const conversations = ref([]);
const selectedConversation = ref(null);
const messages = ref([]);
const loading = ref(false);
const messagesLoading = ref(false);
const autoRefreshTimer = ref(null);

const fetchStats = async () => {
  try {
    const res = await axios.get('/api/whatsapp/stats');
    stats.value = res.data;
  } catch (e) {
    console.error('Failed to load WhatsApp stats:', e);
  }
};

const fetchConversations = async () => {
  try {
    loading.value = true;
    const res = await axios.get('/api/whatsapp/conversations');
    conversations.value = res.data.data || [];
    if (conversations.value.length > 0 && !selectedConversation.value) {
      selectConversation(conversations.value[0]);
    }
  } catch (e) {
    console.error('Failed to load WhatsApp conversations:', e);
  } finally {
    loading.value = false;
  }
};

const selectConversation = async (conv) => {
  selectedConversation.value = conv;
  try {
    messagesLoading.value = true;
    const res = await axios.get(`/api/whatsapp/conversations/${conv.id}/messages`);
    messages.value = res.data.data || [];
  } catch (e) {
    console.error('Failed to load conversation messages:', e);
  } finally {
    messagesLoading.value = false;
  }
};

const formatDate = (isoString) => {
  if (!isoString) return '';
  const date = new Date(isoString);
  return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
};

onMounted(() => {
  fetchStats();
  fetchConversations();
  autoRefreshTimer.value = setInterval(() => {
    fetchStats();
    if (selectedConversation.value) {
      selectConversation(selectedConversation.value);
    } else {
      fetchConversations();
    }
  }, 10000);
});

onUnmounted(() => {
  if (autoRefreshTimer.value) clearInterval(autoRefreshTimer.value);
});
</script>

<template>
  <div class="bg-white dark:bg-slate-900 border border-slate-200 dark:border-slate-800 rounded-2xl p-6 shadow-xl mb-8 transition-colors duration-200">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between pb-6 border-b border-slate-200 dark:border-slate-800 gap-4">
      <div class="flex items-center gap-3">
        <div class="w-12 h-12 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center font-bold shadow-inner">
          <svg class="w-6 h-6" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12.012 2c-5.506 0-9.989 4.478-9.99 9.984 0 1.762.459 3.48 1.332 5.001l-1.416 5.176 5.297-1.389c1.474.803 3.146 1.226 4.774 1.227h.004c5.505 0 9.988-4.478 9.989-9.984 0-2.668-1.037-5.176-2.922-7.062-1.886-1.886-4.394-2.924-7.068-2.924zm5.7 14.167c-.244.688-1.42 1.314-1.961 1.378-.517.061-1.189.096-3.415-.815-2.845-1.163-4.664-4.043-4.806-4.234-.141-.191-1.149-1.528-1.149-2.914 0-1.386.726-2.068.984-2.35.258-.282.563-.353.751-.353.188 0 .376.002.54.01.176.009.412-.067.645.493.245.588.834 2.033.905 2.179.071.146.118.318.024.506-.094.188-.141.306-.282.471-.141.165-.297.369-.424.496-.141.141-.288.295-.124.577.165.282.732 1.205 1.571 1.954 1.079.963 1.989 1.261 2.271 1.402.282.141.447.118.612-.071.165-.188.705-.823.893-1.105.188-.282.376-.235.635-.141.258.094 1.644.775 1.926.916.282.141.47.211.54.329.07.117.07.681-.175 1.369z"/>
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100 flex items-center gap-2">
            WhatsApp Chatbot Monitor
            <span 
              :class="stats.connection.connected ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20' : 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20'"
              class="text-xs font-semibold px-2.5 py-0.5 rounded-full border inline-flex items-center gap-1.5"
            >
              <span class="w-2 h-2 rounded-full" :class="stats.connection.connected ? 'bg-emerald-500 animate-pulse' : 'bg-amber-500'"></span>
              {{ stats.connection.connected ? 'Connected' : 'Offline / Standby' }}
            </span>
          </h3>
          <p class="text-xs text-slate-500 dark:text-slate-400">
            Driver Active: <span class="font-bold text-indigo-600 dark:text-indigo-400 uppercase">{{ stats.connection.driver || 'meta_cloud' }}</span>
          </p>
        </div>
      </div>

      <button 
        @click="fetchStats(); fetchConversations();" 
        class="px-3.5 py-2 text-xs font-semibold bg-slate-100 dark:bg-slate-800 text-slate-700 dark:text-slate-300 hover:bg-slate-200 dark:hover:bg-slate-700 rounded-xl transition flex items-center gap-2 shadow-sm"
      >
        <svg class="w-3.5 h-3.5" :class="loading ? 'animate-spin' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
        </svg>
        Refresh Data
      </button>
    </div>

    <!-- Evolution API QR Code Connection Panel -->
    <div v-if="stats.connection.driver === 'evolution' && !stats.connection.connected" class="my-6 p-6 bg-indigo-50/50 dark:bg-indigo-950/20 border-2 border-dashed border-indigo-200 dark:border-indigo-800 rounded-2xl flex flex-col md:flex-row items-center justify-between gap-6">
      <div class="space-y-2 text-center md:text-left">
        <h4 class="text-base font-bold text-slate-800 dark:text-slate-100 flex items-center justify-center md:justify-start gap-2">
          📱 Sambungkan WhatsApp HP Anda
          <span class="bg-indigo-100 dark:bg-indigo-900 text-indigo-700 dark:text-indigo-300 text-[10px] font-bold px-2 py-0.5 rounded">Evolution API</span>
        </h4>
        <p class="text-xs text-slate-600 dark:text-slate-400 max-w-md">
          1. Buka aplikasi WhatsApp di HP Anda.<br>
          2. Ketuk <b>Pengaturan / Perangkat Tertaut</b> (Linked Devices).<br>
          3. Pindai QR Code di samping untuk mengaktifkan Bot VIPER.
        </p>
        <div class="text-[11px] text-indigo-600 dark:text-indigo-400 font-semibold pt-1">
          ✅ Bebas batasan negara • 100% Gratis • Respon otomatis aktif
        </div>
      </div>

      <div class="flex flex-col items-center shrink-0">
        <div class="bg-white p-3 rounded-2xl shadow-md border border-slate-200 flex items-center justify-center">
          <img 
            v-if="stats.connection.qr_code" 
            :src="stats.connection.qr_code.startsWith('data:') 
              ? stats.connection.qr_code 
              : (stats.connection.qr_code.startsWith('iVBOR') || stats.connection.qr_code.length > 500)
                ? 'data:image/png;base64,' + stats.connection.qr_code
                : 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=' + encodeURIComponent(stats.connection.qr_code)" 
            alt="WhatsApp QR Code" 
            class="w-48 h-48 object-contain"
          />
          <div v-else class="w-48 h-48 flex flex-col items-center justify-center text-slate-400 text-xs text-center p-4">
            <svg class="w-8 h-8 mb-2 animate-spin text-indigo-500" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Menyiapkan QR Code...
          </div>
        </div>
        <button 
          @click="fetchStats" 
          class="mt-2 text-[11px] text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-semibold underline"
        >
          Muat Ulang QR Code
        </button>
      </div>
    </div>

    <!-- Quick Stats Grid -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 my-6">
      <div class="bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-800 rounded-xl p-4">
        <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">Total Chat</span>
        <div class="text-2xl font-black text-slate-800 dark:text-slate-100 mt-1">{{ stats.total_conversations }}</div>
      </div>
      <div class="bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-800 rounded-xl p-4">
        <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">Aktif Hari Ini</span>
        <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400 mt-1">{{ stats.active_today }}</div>
      </div>
      <div class="bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-800 rounded-xl p-4">
        <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">Pesan Hari Ini</span>
        <div class="text-2xl font-black text-indigo-600 dark:text-indigo-400 mt-1">{{ stats.messages_today }}</div>
      </div>
      <div class="bg-slate-50 dark:bg-slate-800/50 border border-slate-200/80 dark:border-slate-800 rounded-xl p-4">
        <span class="text-xs text-slate-500 dark:text-slate-400 font-medium">Gambar Di-Scan</span>
        <div class="text-2xl font-black text-purple-600 dark:text-purple-400 mt-1">{{ stats.images_processed }}</div>
      </div>
    </div>

    <!-- Main Chat Workspace -->
    <div class="grid grid-cols-1 md:grid-cols-3 border border-slate-200 dark:border-slate-800 rounded-xl overflow-hidden min-h-[380px]">
      <!-- Conversations List -->
      <div class="border-r border-slate-200 dark:border-slate-800 bg-slate-50/50 dark:bg-slate-900/50">
        <div class="p-3 border-b border-slate-200 dark:border-slate-800 text-xs font-bold text-slate-500 dark:text-slate-400 uppercase tracking-wider">
          Percakapan Terbaru
        </div>
        <div class="divide-y divide-slate-100 dark:divide-slate-800/60 max-h-[350px] overflow-y-auto">
          <div v-if="conversations.length === 0" class="p-6 text-center text-xs text-slate-400">
            Belum ada pesan WhatsApp masuk.
          </div>
          <button
            v-for="conv in conversations"
            :key="conv.id"
            @click="selectConversation(conv)"
            :class="selectedConversation?.id === conv.id ? 'bg-indigo-50/80 dark:bg-indigo-950/30 text-indigo-900 dark:text-indigo-200 border-l-4 border-indigo-600' : 'hover:bg-slate-100 dark:hover:bg-slate-800/40 text-slate-700 dark:text-slate-300'"
            class="w-full text-left p-3.5 transition flex items-start gap-3"
          >
            <div class="w-8 h-8 rounded-full bg-slate-200 dark:bg-slate-700 flex items-center justify-center font-bold text-xs shrink-0">
              {{ conv.sender_name ? conv.sender_name.charAt(0).toUpperCase() : 'WA' }}
            </div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between">
                <span class="text-xs font-bold truncate">{{ conv.sender_name || conv.phone_number }}</span>
                <span class="text-[10px] text-slate-400">{{ formatDate(conv.last_message_at) }}</span>
              </div>
              <p class="text-xs text-slate-500 dark:text-slate-400 truncate mt-0.5">
                {{ conv.last_message || 'Tidak ada pesan' }}
              </p>
            </div>
          </button>
        </div>
      </div>

      <!-- Messages Detail View -->
      <div class="md:col-span-2 flex flex-col bg-white dark:bg-slate-950">
        <div v-if="selectedConversation" class="p-3 border-b border-slate-200 dark:border-slate-800 flex items-center justify-between bg-slate-50 dark:bg-slate-900">
          <div>
            <div class="text-xs font-bold text-slate-800 dark:text-slate-100">
              {{ selectedConversation.sender_name || selectedConversation.phone_number }}
            </div>
            <div class="text-[10px] text-slate-400">+{{ selectedConversation.phone_number }}</div>
          </div>
          <span class="text-[10px] px-2 py-0.5 rounded bg-emerald-100 dark:bg-emerald-900/40 text-emerald-700 dark:text-emerald-300 font-medium">
            Active Session
          </span>
        </div>

        <div class="flex-1 p-4 overflow-y-auto max-h-[300px] space-y-3 custom-scrollbar">
          <div v-if="!selectedConversation" class="h-full flex items-center justify-center text-xs text-slate-400">
            Pilih kontak di sebelah kiri untuk melihat histori percakapan.
          </div>
          <div v-else-if="messagesLoading" class="h-full flex items-center justify-center text-xs text-slate-400">
            Memuat histori pesan...
          </div>
          <div
            v-for="msg in messages"
            :key="msg.id"
            :class="msg.direction === 'outgoing' ? 'justify-end' : 'justify-start'"
            class="flex"
          >
            <div
              :class="msg.direction === 'outgoing' 
                ? 'bg-indigo-600 text-white rounded-2xl rounded-tr-none' 
                : 'bg-slate-100 dark:bg-slate-800 text-slate-800 dark:text-slate-100 rounded-2xl rounded-tl-none'"
              class="max-w-[80%] p-3 text-xs shadow-sm space-y-1"
            >
              <div v-if="msg.type === 'image'" class="font-bold text-[10px] opacity-80 flex items-center gap-1 mb-1">
                🖼️ Gambar Pricelist
              </div>
              <p class="whitespace-pre-wrap leading-relaxed">{{ msg.content }}</p>
              <div class="text-[9px] opacity-70 text-right mt-1">
                {{ formatDate(msg.created_at) }}
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
  width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
  background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
  background-color: #cbd5e1;
  border-radius: 10px;
}
</style>
