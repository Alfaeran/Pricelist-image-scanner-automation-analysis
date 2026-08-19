<script setup>
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, onMounted, onUnmounted, nextTick, computed, watch } from "vue";
import axios from "axios";
import ChartViewer from "@/Components/ChartViewer.vue";
import WhatsAppMonitor from "@/Components/WhatsAppMonitor.vue";
import Swal from "sweetalert2";
import { Line, Bar, Scatter } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip as ChartTooltip, Legend } from 'chart.js'
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';
import anime from 'animejs';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, ChartTooltip, Legend)

const showWhatsAppMonitor = ref(false);

const isDark = ref(false);
const toggleDarkMode = () => {
  isDark.value = !isDark.value;
  localStorage.setItem('darkMode', isDark.value);
  if (isDark.value) document.documentElement.classList.add('dark');
  else document.documentElement.classList.remove('dark');
};
onMounted(() => {
  isDark.value = localStorage.getItem('darkMode') === 'true' || 
          (!('darkMode' in localStorage) && window.matchMedia('(prefers-color-scheme: dark)').matches);
  if (isDark.value) document.documentElement.classList.add('dark');
  else document.documentElement.classList.remove('dark');
  fetchLearnedPatterns();
});

const learnedPatterns = ref([]);
const fetchLearnedPatterns = async () => {
  try {
    const res = await axios.get('/api/learned-patterns');
    if (res.data.status === 'success') {
      learnedPatterns.value = res.data.data;
    }
  } catch (e) {
    console.error("Gagal mengambil pola AI", e);
  }
};

// baselineProducts will be initialized from props below

const globalNotification = ref({ show: false, type: 'info', message: '' });

const showNotification = (type, message) => {
  globalNotification.value = { show: true, type, message };
  setTimeout(() => {
    globalNotification.value.show = false;
  }, 5000);
};

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

  console.error("Axios Error:", e.response || e);
  showNotification('error', "Terjadi Kesalahan: " + msg);
};
import { marked } from "marked";

const props = defineProps({
  pricelists: Array,
  baselineProducts: Array,
});

const baselineProducts = ref(props.baselineProducts || []);

// ─── State ────────────────────────────────────────────────────────
const form = useForm({ message: "", images: [], locations: [], manual_timestamp: "" });
const selectedRegions = ref([]);
const fileInput = ref(null);
const chatContainer = ref(null);
const sidebarOpen = ref(true);
const sidebarTab = ref("history"); // 'history' | 'keys' | 'models'
const isChatOpen = ref(false); // Controls chat popup visibility
const inputType = ref('scan'); // 'scan' | 'data'

const aiInsightData = ref(null);
const aiInsightLoading = ref(false);

// Filter States
const isFilterOpen = ref(false);
const insightTimeFilter = ref('Semua Waktu');
const insightStartDate = ref('');
const insightEndDate = ref('');
const insightRegionFilter = ref('Semua Region');
const insightBranchFilter = ref('Semua Branch');
const activeSummaryTab = ref('yield');
const filters = ref({
  providers: [],
  categories: [],
  flags: [],
  search: '', // package name
  location: '', // location
  priceMin: null,
  priceMax: null,
  gbMin: null,
  gbMax: null,
  daysMin: null,
  daysMax: null,
  yieldMin: null,
  yieldMax: null,
  dateStart: '',
  dateEnd: ''
});

// Market Trend States
const trendDateRange = ref(null);
const trendCircle = ref('');
const trendRegion = ref('');
const trendBranch = ref('');
const trendMetric = ref('avg_price');
const trendRawData = ref(null);
const trendLoading = ref(false);
const trendFiles = ref([]);
const isTrendFileFilterOpen = ref(false);
const marketSummaryFilter = ref('all');
const trendActiveProviders = ref([]);

const toggleTrendProvider = (prov) => {
  if (trendActiveProviders.value.length === 0) {
    trendActiveProviders.value.push(prov);
  } else {
    const idx = trendActiveProviders.value.indexOf(prov);
    if (idx > -1) {
      trendActiveProviders.value.splice(idx, 1);
    } else {
      trendActiveProviders.value.push(prov);
    }
  }
};

const toggleTrendProviderGroup = (groupName) => {
  const group = providerGroups.find(g => g.parent === groupName);
  if (!group) return;
  const childValues = group.children.map(c => c.value);

  if (trendActiveProviders.value.length === 0) {
    trendActiveProviders.value = [...childValues];
  } else {
    const allActive = childValues.every(val => trendActiveProviders.value.includes(val));
    if (allActive) {
      trendActiveProviders.value = trendActiveProviders.value.filter(p => !childValues.includes(p));
    } else {
      childValues.forEach(val => {
        if (!trendActiveProviders.value.includes(val)) {
          trendActiveProviders.value.push(val);
        }
      });
    }
  }
};

const isTrendProviderActive = (prov) => {
  return trendActiveProviders.value.length === 0 || trendActiveProviders.value.includes(prov);
};

const trendProviders = computed(() => {
  if (!trendRawData.value || !trendRawData.value.providers) return [];
  return Object.keys(trendRawData.value.providers).sort();
});

// --- Anime.js Utils ---
let loadingAnim = null;

const availableTrendFiles = computed(() => {
  return Array.from(new Set(props.pricelists.map(p => p.filename))).sort();
});
const startLoader = () => {
  const el = document.getElementById('global-loader');
  if (!el) return;
  el.style.width = '0%';
  el.style.opacity = '1';
  loadingAnim = anime({
    targets: '#global-loader',
    width: ['0%', '80%'],
    duration: 2000,
    easing: 'easeInOutQuart',
    loop: false
  });
};
const finishLoader = () => {
  const el = document.getElementById('global-loader');
  if (!el) return;
  if(loadingAnim) loadingAnim.pause();
  anime({
    targets: '#global-loader',
    width: '100%',
    duration: 500,
    easing: 'easeOutQuart',
    complete: () => {
      anime({
        targets: '#global-loader',
        opacity: 0,
        duration: 300,
        easing: 'linear',
        complete: () => { el.style.width = '0%'; }
      });
    }
  });
};

watch(() => form.processing, (newVal) => { if(newVal) startLoader(); else finishLoader(); });
watch(() => aiInsightLoading.value, (newVal) => { if(newVal) startLoader(); else finishLoader(); });
watch(() => trendLoading.value, (newVal) => { if(newVal) startLoader(); else finishLoader(); });

onMounted(() => {
  // Intersection Observer for Anime.js scroll animations
  const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
      if(entry.isIntersecting) {
        anime({
          targets: entry.target,
          translateY: [20, 0],
          opacity: [0, 1],
          duration: 600,
          easing: 'easeOutCubic'
        });
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.1 });
  
  // Watch for new elements with class 'anim-on-scroll'
  const mutationObserver = new MutationObserver((mutations) => {
    document.querySelectorAll('.anim-on-scroll:not(.observed)').forEach(el => {
      el.classList.add('observed');
      el.style.opacity = '0';
      observer.observe(el);
    });
  });
  mutationObserver.observe(document.body, { childList: true, subtree: true });
});
const branchOptions = [
  { group: 'Central Java', options: ['Semarang', 'Surakarta', 'Magelang', 'Salatiga', 'Tegal', 'Pekalongan', 'Cilacap', 'Banyumas', 'Purbalingga', 'Banjarnegara', 'Kebumen', 'Purworejo', 'Wonosobo', 'Boyolali', 'Klaten', 'Sukoharjo', 'Wonogiri', 'Karanganyar', 'Sragen', 'Grobogan', 'Blora', 'Rembang', 'Pati', 'Kudus', 'Jepara', 'Demak', 'Temanggung', 'Kendal', 'Batang', 'Pemalang', 'Brebes'] },
  { group: 'East Java', options: ['Surabaya', 'Malang', 'Kediri', 'Madiun', 'Mojokerto', 'Pasuruan', 'Probolinggo', 'Batu', 'Blitar', 'Bangkalan', 'Banyuwangi', 'Bojonegoro', 'Bondowoso', 'Gresik', 'Jember', 'Jombang', 'Lamongan', 'Lumajang', 'Magetan', 'Nganjuk', 'Ngawi', 'Pacitan', 'Pamekasan', 'Ponorogo', 'Sampang', 'Sidoarjo', 'Situbondo', 'Sumenep', 'Trenggalek', 'Tuban', 'Tulungagung'] },
  { group: 'Bali Nusra', options: ['Denpasar', 'Badung', 'Bangli', 'Buleleng', 'Gianyar', 'Jembrana', 'Karangasem', 'Klungkung', 'Tabanan', 'Mataram', 'Bima', 'Sumbawa', 'Dompu', 'Lombok Barat', 'Lombok Tengah', 'Lombok Timur', 'Lombok Utara', 'Sumbawa Barat', 'Kupang', 'Ende', 'Flores Timur', 'Sikka', 'Ngada', 'Manggarai', 'Sumba', 'Belu', 'Alor'] }
];

const providerGroups = [
  {
    parent: 'IOH',
    parentColor: '#FCD116',
    parentTextColor: '#ED1C24',
    bgColor: '#ffffff',
    children: [
      { name: 'IM3', color: '#FCD116', value: 'IM3' },
      { name: '3ID', color: '#D6005E', value: '3' }
    ]
  },
  {
    parent: 'Telkomsel',
    parentColor: '#E60A14',
    bgColor: '#ffffff',
    children: [
      { name: 'TELKOMSEL', color: '#E60A14', value: 'TELKOMSEL' },
      { name: 'BY.U', color: '#00B6ED', value: 'BY.U' }
    ]
  },
  {
    parent: 'XL',
    parentColor: '#0B2F75',
    bgColor: '#ffffff',
    children: [
      { name: 'XL', color: '#0B2F75', value: 'XL' },
      { name: 'AXIS', color: '#6F2B8C', value: 'AXIS' },
      { name: 'SMARTFREN', color: '#D1006B', value: 'SMARTFREN' }
    ]
  }
];

const getProviderColor = (providerName) => {
  const prov = providerName.toUpperCase();
  if (prov === '3' || prov.includes('TRI') || prov === '3ID') return '#D6005E'; 
  if (prov.includes('AXIS')) return '#6F2B8C'; 
  if (prov.includes('XL')) return '#0B2F75'; 
  if (prov.includes('BY.U') || prov.includes('BYU')) return '#00B6ED'; 
  if (prov.includes('TELKOMSEL') || prov.includes('TSEL')) return '#E60A14'; 
  if (prov.includes('SMARTFREN') || prov.includes('SF')) return '#D1006B'; 
  if (prov.includes('INDOSAT') || prov.includes('IM3')) return '#FCD116';
  
  // Hash string to color for unknown providers
  let hash = 0;
  for (let i = 0; i < prov.length; i++) {
    hash = prov.charCodeAt(i) + ((hash << 5) - hash);
  }
  const c = (hash & 0x00FFFFFF)
    .toString(16)
    .toUpperCase();
  return '#' + '00000'.substring(0, 6 - c.length) + c;
};

// Compute chart data based on raw data and selected metric
const trendChartData = computed(() => {
  if (!trendRawData.value || !trendRawData.value.labels) {
    return { labels: [], datasets: [] };
  }
  
  const datasets = [];
  
  for (const [provider, data] of Object.entries(trendRawData.value.providers)) {
    if (!isTrendProviderActive(provider)) continue;
    
    datasets.push({
      label: provider,
      backgroundColor: getProviderColor(provider) + '1A',
      borderColor: getProviderColor(provider),
      data: data[trendMetric.value],
      borderWidth: 3,
      pointRadius: 4,
      pointHoverRadius: 6,
      tension: 0.3,
      fill: false
    });
  }
  
  return {
    labels: trendRawData.value.labels,
    datasets: datasets
  };
});

const trendChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      display: false,
      position: 'bottom',
      labels: {
        color: '#9CA3AF',
        usePointStyle: true,
        padding: 20
      }
    },
    tooltip: {
      mode: 'index',
      intersect: false,
      backgroundColor: 'rgba(255, 255, 255, 0.95)',
      titleColor: '#1e293b',
      bodyColor: '#475569',
      borderColor: '#e2e8f0',
      borderWidth: 1,
      titleFont: { family: "'Inter', sans-serif", size: 13 },
      bodyFont: { family: "'Inter', sans-serif", size: 12 },
      callbacks: {
        label: function(context) {
          let label = context.dataset.label || '';
          if (label) {
            label += ': ';
          }
          if (context.parsed.y !== null) {
            if (trendMetric.value === 'avg_price') {
              label += new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(context.parsed.y);
            } else if (trendMetric.value === 'avg_yield') {
              label += 'Rp' + new Intl.NumberFormat('id-ID').format(context.parsed.y) + '/GB';
            } else {
              label += context.parsed.y + ' Paket';
            }
          }
          return label;
        }
      }
    }
  },
  scales: {
    x: {
      grid: {
        color: '#f1f5f9',
        drawBorder: false
      },
      ticks: {
        color: '#64748b'
      }
    },
    y: {
      grid: {
        color: '#f1f5f9',
        drawBorder: false
      },
      ticks: {
        color: '#64748b',
        callback: function(value) {
          if (trendMetric.value === 'avg_price') return 'Rp' + (value / 1000) + 'k';
          if (trendMetric.value === 'avg_yield') return 'Rp' + value;
          return value;
        }
      }
    }
  },
  interaction: {
    mode: 'nearest',
    axis: 'x',
    intersect: false
  }
};

const fetchTrendData = async () => {
  trendLoading.value = true;
  try {
    let start_date = null;
    let end_date = null;
    if (trendDateRange.value && trendDateRange.value.length === 2) {
      const s = trendDateRange.value[0];
      const e = trendDateRange.value[1];
      if (s instanceof Date) {
        start_date = s.getFullYear() + '-' + String(s.getMonth()+1).padStart(2, '0') + '-' + String(s.getDate()).padStart(2, '0');
      } else if (typeof s === 'string') {
        start_date = s.substring(0, 10);
      }
      if (e instanceof Date) {
        end_date = e.getFullYear() + '-' + String(e.getMonth()+1).padStart(2, '0') + '-' + String(e.getDate()).padStart(2, '0');
      } else if (typeof e === 'string') {
        end_date = e.substring(0, 10);
      } else if (s && !e) {
        // If only start date is selected in range, use today as end date
        const today = new Date();
        end_date = today.getFullYear() + '-' + String(today.getMonth()+1).padStart(2, '0') + '-' + String(today.getDate()).padStart(2, '0');
      }
    }

    const res = await axios.get(route('api.trends'), {
      params: { 
        start_date, end_date, filenames: trendFiles.value, 
        circle: trendCircle.value, region: trendRegion.value, branch: trendBranch.value,
        _: new Date().getTime() 
      }
    });
    trendRawData.value = res.data;
  } catch (e) {
    showError(e, "Gagal mengambil data trend");
  } finally {
    trendLoading.value = false;
  }
};

const resetFilters = () => {
  filters.value = {
    providers: [],
    categories: [],
    flags: [],
    search: '',
    priceMin: null,
    priceMax: null,
    gbMin: null,
    gbMax: null,
    daysMin: null,
    daysMax: null,
    yieldMin: null,
    yieldMax: null,
    dateStart: '',
    dateEnd: ''
  };
};

const activeSessionId = ref(null);

watch(() => props.pricelists, () => {
  nextTick(() => {
    anime({
      targets: '.sidebar-item',
      translateX: [-20, 0],
      opacity: [0, 1],
      delay: anime.stagger(50),
      easing: 'easeOutQuad',
      duration: 400
    });
  });
}, { deep: true, immediate: true });

watch(activeSessionId, () => {
  nextTick(() => {
    if (activeSessionId.value) {
      anime({
        targets: '.anim-on-scroll:not(.observed)',
        translateY: [20, 0],
        opacity: [0, 1],
        delay: anime.stagger(100),
        easing: 'easeOutQuart',
        duration: 600
      });
      // Trigger SVG drawing for empty state icons if any are in the new layout
      anime({
        targets: '.anime-svg-draw path, .anime-svg-draw line, .anime-svg-draw polyline',
        strokeDashoffset: [anime.setDashoffset, 0],
        easing: 'easeInOutSine',
        duration: 1200,
        delay: anime.stagger(100),
        direction: 'alternate',
        loop: false
      });
    }
  });
}, { immediate: true });

// Data Table & Insights toggles (per pricelist id)
const activeTables = ref({});
const activeInsights = ref({});
const selectedEditRows = ref({});
const editingPackageIds = ref({});
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

const availableProviders = computed(() => {
  if (!activeSession.value || !activeSession.value.packages) return [];
  return [...new Set(activeSession.value.packages.map(p => normalizeProvider(p.provider)))].filter(Boolean);
});

const availableCategories = computed(() => {
  if (!activeSession.value || !activeSession.value.packages) return [];
  return [...new Set(activeSession.value.packages.map(p => p.category))].filter(Boolean);
});

const filteredPackagesList = computed(() => {
  if (!activeSession.value || !activeSession.value.packages) return [];
  
  // Insights filtering logic
  // Sort by id descending so newest appended data is on top
  const pkgs = [...activeSession.value.packages].sort((a, b) => b.id - a.id);
  
  return pkgs.filter(pkg => {
    const f = filters.value;
    
    const prov = normalizeProvider(pkg.provider);
    if (f.providers.length > 0 && !f.providers.includes(prov)) return false;
    if (f.categories.length > 0 && !f.categories.includes(pkg.category)) return false;
    if (f.search && pkg.package_name && !pkg.package_name.toLowerCase().includes(f.search.toLowerCase())) return false;
    
    const loc = pkg.branch || pkg.image_location || '';
    if (f.location && !loc.toLowerCase().includes(f.location.toLowerCase())) return false;
    
    const gb = Number(pkg.gb);
    if (f.gbMin !== null && f.gbMin !== '' && gb < Number(f.gbMin)) return false;
    if (f.gbMax !== null && f.gbMax !== '' && gb > Number(f.gbMax)) return false;
    
    const price = Number(pkg.price);
    if (f.priceMin !== null && f.priceMin !== '' && price < Number(f.priceMin)) return false;
    if (f.priceMax !== null && f.priceMax !== '' && price > Number(f.priceMax)) return false;
    
    const days = Number(pkg.days);
    if (f.daysMin !== null && f.daysMin !== '' && days < Number(f.daysMin)) return false;
    if (f.daysMax !== null && f.daysMax !== '' && days > Number(f.daysMax)) return false;
    
    const yieldVal = Number(pkg.yield_val);
    if (f.yieldMin !== null && f.yieldMin !== '' && yieldVal < Number(f.yieldMin)) return false;
    if (f.yieldMax !== null && f.yieldMax !== '' && yieldVal > Number(f.yieldMax)) return false;
    
    const tsStr = pkg.image_timestamp || (pkg.created_at ? pkg.created_at.replace('T', ' ').substring(0, 19) : '');
    if (f.dateStart && tsStr && !tsStr.toLowerCase().includes(f.dateStart.toLowerCase())) return false;
    
    if (f.flags && f.flags.length > 0) {
      let matched = false;
      
      const comp = comparisonResults.value[activeSessionId.value]?.[pkg.id];
      const status = comp ? comp.status : null;
      if (f.flags.includes(status)) matched = true;
      
      if (f.flags.includes('is_new_product') && pkg.is_new_product) matched = true;
      if (f.flags.includes('is_price_changed') && pkg.is_price_changed) matched = true;
      
      if (!matched) return false;
    }

    return true;
  });
});

const activeKeyCount = computed(
  () => apiKeys.value.filter((k) => k.is_active).length,
);

const insightFilteredPackages = computed(() => {
  if (!activeSession.value || !activeSession.value.packages) return [];
  
  const pkgs = activeSession.value.packages.map(pkg => ({
    ...pkg,
    provider: normalizeProvider(pkg.provider)
  }));
  
  if (insightTimeFilter.value === 'Semua Waktu') {
    return pkgs;
  }
  
  let maxTimestamp = new Date().getTime();
  
  if (pkgs.length > 0) {
    let maxTime = 0;
    pkgs.forEach(pkg => {
      const dateStr = pkg.image_timestamp ? pkg.image_timestamp.replace(' ', 'T') : null;
      let pDate = dateStr ? new Date(dateStr) : null;
      if (!pDate || isNaN(pDate.getTime())) {
        pDate = new Date(pkg.created_at || (activeSession.value ? activeSession.value.created_at : new Date()));
      }
      if (pDate && !isNaN(pDate.getTime()) && pDate.getTime() > maxTime) {
        maxTime = pDate.getTime();
      }
    });
    if (maxTime > 0) maxTimestamp = maxTime;
  }

  const referenceDate = new Date(maxTimestamp);
  const startOfToday = new Date(referenceDate.getFullYear(), referenceDate.getMonth(), referenceDate.getDate());
  
  const startOfWeek = new Date(startOfToday);
  const day = startOfWeek.getDay() || 7; 
  startOfWeek.setDate(startOfWeek.getDate() - day + 1);
  
  const startOfMonth = new Date(referenceDate.getFullYear(), referenceDate.getMonth(), 1);
  const startOfYear = new Date(referenceDate.getFullYear(), 0, 1);
  
  return pkgs.filter(pkg => {
    const dateStr = pkg.image_timestamp ? pkg.image_timestamp.replace(' ', 'T') : null;
    let pkgDate = dateStr ? new Date(dateStr) : null;
    
    if (!pkgDate || isNaN(pkgDate.getTime())) {
      pkgDate = new Date(pkg.created_at || (activeSession.value ? activeSession.value.created_at : new Date()));
    }
    
    if (!pkgDate || isNaN(pkgDate.getTime())) return false;
    
    if (pkg.category === 'REJECTED') return false;
    
    if (insightRegionFilter.value !== 'Semua Region' && pkg.region !== insightRegionFilter.value) {
      return false;
    }
    
    if (insightBranchFilter.value !== 'Semua Branch' && pkg.branch !== insightBranchFilter.value) {
      return false;
    }
    
    if (insightTimeFilter.value === 'Hari Ini') {
      return pkgDate >= startOfToday;
    } else if (insightTimeFilter.value === 'Minggu Ini') {
      return pkgDate >= startOfWeek;
    } else if (insightTimeFilter.value === 'Bulan Ini') {
      return pkgDate >= startOfMonth;
    } else if (insightTimeFilter.value === 'Tahun Ini') {
      return pkgDate >= startOfYear;
    } else if (insightTimeFilter.value === 'Rentang Waktu') {
      if (insightStartDate.value) {
        const start = new Date(insightStartDate.value);
        start.setHours(0, 0, 0, 0);
        if (pkgDate < start) return false;
      }
      if (insightEndDate.value) {
        const end = new Date(insightEndDate.value);
        end.setHours(23, 59, 59, 999);
        if (pkgDate > end) return false;
      }
      return true;
    }
    return true;
  });
});

const rejectedPackages = computed(() => {
  if (!activeSession.value || !activeSession.value.packages) return [];
  return activeSession.value.packages.filter(pkg => pkg.category === 'REJECTED' || pkg.is_anomaly);
});

const marketFilteredPackages = computed(() => {
  let pkgs = insightFilteredPackages.value;
  if (marketSummaryFilter.value === 'harian') {
    pkgs = pkgs.filter(p => p.category === 'Harian (Sachet)');
  } else if (marketSummaryFilter.value === 'mingguan') {
    pkgs = pkgs.filter(p => p.category === 'Mingguan');
  } else if (marketSummaryFilter.value === 'bulanan_sachet') {
    pkgs = pkgs.filter(p => p.category === 'Bulanan (Standar)' || p.category === 'Bulanan');
  } else if (marketSummaryFilter.value === 'bulanan_premium') {
    pkgs = pkgs.filter(p => p.category === 'Bulanan (Premium/Jumbo)');
  }
  return pkgs;
});

const overallCompetitiveness = computed(() => {
  const pkgs = marketFilteredPackages.value;
  if (!pkgs || pkgs.length === 0) return [];
  
  const monthlyRules = [
    { name: "30K - 50K", check: (p) => p >= 30000 && p <= 50000 },
    { name: "50K - 100K", check: (p) => p > 50000 && p <= 100000 },
    { name: "> 100K", check: (p) => p > 100000 },
  ];
  const validityRules = [
    { name: "1 day", check: (d) => d === 1 },
    { name: "2 days", check: (d) => d === 2 },
    { name: "3 days", check: (d) => d === 3 },
    { name: "5 days", check: (d) => d === 5 },
    { name: "7 days", check: (d) => d === 7 },
    { name: ">10 days", check: (d) => d >= 10 },
  ];
  
  const monthlyPkgs = pkgs.filter(p => p.category?.toLowerCase().includes("monthly") || Number(p.days) >= 28);
  const validityPkgs = pkgs.filter(p => !p.category?.toLowerCase().includes("monthly") && Number(p.days) < 28);
  
  const minYields = {};
  monthlyRules.forEach(rule => {
    const matches = monthlyPkgs.filter(pkg => rule.check(Number(pkg.price)) && Number(pkg.yield_val) > 0);
    if (matches.length > 0) minYields[rule.name] = Math.min(...matches.map(m => Number(m.yield_val)));
  });
  validityRules.forEach(rule => {
    const matches = validityPkgs.filter(pkg => rule.check(Number(pkg.days)) && Number(pkg.yield_val) > 0);
    if (matches.length > 0) minYields[rule.name] = Math.min(...matches.map(m => Number(m.yield_val)));
  });
  
  const providerScores = {};
  const evaluatePackage = (pkg, rule) => {
    if (!rule) return;
    const yieldVal = Number(pkg.yield_val);
    if (yieldVal > 0 && minYields[rule.name]) {
      const score = (minYields[rule.name] / yieldVal) * 100;
      const prov = pkg.provider;
      if (!providerScores[prov]) providerScores[prov] = { totalScore: 0, count: 0 };
      providerScores[prov].totalScore += score;
      providerScores[prov].count += 1;
    }
  };
  
  monthlyPkgs.forEach(pkg => evaluatePackage(pkg, monthlyRules.find(r => r.check(Number(pkg.price)))));
  validityPkgs.forEach(pkg => evaluatePackage(pkg, validityRules.find(r => r.check(Number(pkg.days)))));
  
  const result = Object.keys(providerScores).map(provider => ({
    provider,
    score: Math.round(providerScores[provider].totalScore / providerScores[provider].count)
  }));
  return result.sort((a, b) => b.score - a.score);
});

const formatNumber = (num, digits = 1) => Number(num).toLocaleString('id-ID', { maximumFractionDigits: digits });

const yieldDistributionChartData = computed(() => {
  let pkgs = marketFilteredPackages.value;
  if (!pkgs || pkgs.length === 0) return { labels: [], datasets: [] };

  const baseProviders = ['3', 'AXIS', 'XL', 'BY.U', 'TELKOMSEL', 'SMARTFREN', 'IM3'];
  
  const yieldMap = {};
  baseProviders.forEach(provider => {
    const provPkgs = pkgs.filter(p => p.provider === provider && Number(p.yield_val) > 0);
    if (provPkgs.length === 0) yieldMap[provider] = Infinity;
    else yieldMap[provider] = Math.max(...provPkgs.map(p => Number(p.yield_val)));
  });

  const sortedProviders = [...baseProviders].sort((a, b) => yieldMap[a] - yieldMap[b]);

  const datasetData = sortedProviders.map(provider => yieldMap[provider] === Infinity ? 0 : yieldMap[provider]);
  const backgroundColors = sortedProviders.map(provider => getProviderColor(provider));

  return {
    labels: sortedProviders,
    datasets: [{
      label: 'Yield Range (Rp/GB)',
      data: datasetData,
      backgroundColor: backgroundColors,
      borderWidth: 0,
      borderRadius: 0
    }]
  };
});

const overallCompetitivenessChartData = computed(() => {
  const data = overallCompetitiveness.value;
  if (!data || data.length === 0) return { labels: [], datasets: [] };

  const baseProviders = ['3', 'AXIS', 'XL', 'BY.U', 'TELKOMSEL', 'SMARTFREN', 'IM3'];
  const compData = overallCompetitiveness.value || [];
  const rankedProviders = compData.map(d => d.provider);
  const sortedProviders = [...new Set([...rankedProviders, ...baseProviders])];

  const datasetData = sortedProviders.map(provider => {
    const item = data.find(d => d.provider === provider);
    return item ? item.score : 0;
  });
  
  const backgroundColors = sortedProviders.map(provider => getProviderColor(provider));

  return {
    labels: sortedProviders,
    datasets: [{
      label: 'Competitiveness Score',
      data: datasetData,
      backgroundColor: backgroundColors,
      borderWidth: 0,
      borderRadius: 0
    }]
  };
});

const competitiveHeatmapData = computed(() => {
  let pkgs = marketFilteredPackages.value;
  if (!pkgs || pkgs.length === 0) return { columns: [], rows: [] };

  const baseProviders = ['3', 'AXIS', 'XL', 'BY.U', 'TELKOMSEL', 'SMARTFREN', 'IM3'];
  const compData = overallCompetitiveness.value || [];
  const rankedProviders = compData.map(d => d.provider);
  const sortedProviders = [...new Set([...rankedProviders, ...baseProviders])];
  
  const columns = [
    { key: 'overall', label: 'Overall' },
    { key: 'harian', label: 'Daily', filter: (p) => p.category === 'Harian (Sachet)' || Number(p.days) <= 3 },
    { key: 'weekly', label: 'Weekly', filter: (p) => p.category === 'Mingguan' || (Number(p.days) > 3 && Number(p.days) <= 7) },
    { key: 'monthly', label: 'Monthly', filter: (p) => (p.category && p.category.includes('Bulanan')) || Number(p.days) >= 28 },
    { key: 'premium', label: 'Premium', filter: (p) => (p.category === 'Bulanan (Premium/Jumbo)') || (Number(p.days) >= 28 && Number(p.price) > 50000) }
  ];

  const columnMetrics = {};
  columns.forEach(col => {
    if (col.key === 'overall') {
      const data = overallCompetitiveness.value || [];
      const scores = data.map(d => d.score).sort((a,b) => b - a); // descending
      columnMetrics[col.key] = sortedProviders.reduce((acc, prov) => {
        const item = data.find(d => d.provider === prov);
        if (!item) {
          acc[prov] = 'gray';
        } else {
          const rank = scores.indexOf(item.score);
          const total = scores.length;
          const percentile = rank / total;
          if (percentile <= 0.33) acc[prov] = 'green';
          else if (percentile <= 0.66) acc[prov] = 'yellow';
          else acc[prov] = 'red';
        }
        return acc;
      }, {});
    } else {
      const provYields = sortedProviders.map(prov => {
        const provPkgs = pkgs.filter(p => p.provider === prov && col.filter(p) && Number(p.yield_val) > 0);
        if (provPkgs.length === 0) return { provider: prov, val: Infinity };
        const minYield = Math.min(...provPkgs.map(p => Number(p.yield_val)));
        return { provider: prov, val: minYield };
      });
      const validYields = provYields.filter(p => p.val !== Infinity).map(p => p.val).sort((a,b) => a - b);
      
      columnMetrics[col.key] = sortedProviders.reduce((acc, prov) => {
        const item = provYields.find(p => p.provider === prov);
        if (item.val === Infinity || validYields.length === 0) {
          acc[prov] = 'gray';
        } else {
          const rank = validYields.indexOf(item.val);
          const total = validYields.length;
          const percentile = rank / total;
          if (percentile <= 0.33) acc[prov] = 'green';
          else if (percentile <= 0.66) acc[prov] = 'yellow';
          else acc[prov] = 'red';
        }
        return acc;
      }, {});
    }
  });

  const rows = sortedProviders.map(prov => {
    const row = { provider: prov, cells: {} };
    columns.forEach(col => {
      row.cells[col.key] = columnMetrics[col.key][prov];
    });
    return row;
  });

  return {
    columns: columns,
    rows: rows
  };
});

// ─── Competitive Yield Landscape (Monthly & Sachet) ────────────────────────
const yieldLandscapeActiveProviders = ref([]);

const toggleYieldProvider = (prov) => {
  if (yieldLandscapeActiveProviders.value.length === 0) {
    yieldLandscapeActiveProviders.value.push(prov);
  } else {
    const idx = yieldLandscapeActiveProviders.value.indexOf(prov);
    if (idx > -1) {
      yieldLandscapeActiveProviders.value.splice(idx, 1);
    } else {
      yieldLandscapeActiveProviders.value.push(prov);
    }
  }
};

const toggleYieldProviderGroup = (groupName) => {
  const group = providerGroups.find(g => g.parent === groupName);
  if (!group) return;
  const childValues = group.children.map(c => c.value);

  if (yieldLandscapeActiveProviders.value.length === 0) {
    yieldLandscapeActiveProviders.value = [...childValues];
  } else {
    const allActive = childValues.every(val => yieldLandscapeActiveProviders.value.includes(val));
    if (allActive) {
      yieldLandscapeActiveProviders.value = yieldLandscapeActiveProviders.value.filter(p => !childValues.includes(p));
    } else {
      childValues.forEach(val => {
        if (!yieldLandscapeActiveProviders.value.includes(val)) {
          yieldLandscapeActiveProviders.value.push(val);
        }
      });
    }
  }
};

const isYieldProviderActive = (prov) => {
  return yieldLandscapeActiveProviders.value.length === 0 || yieldLandscapeActiveProviders.value.includes(prov);
};

const yieldLandscapeProviders = computed(() => {
  const pkgs = insightFilteredPackages.value;
  if (!pkgs || pkgs.length === 0) return [];
  const set = new Set();
  pkgs.forEach(p => {
    if (p.provider) set.add(p.provider.toUpperCase().trim());
  });
  return Array.from(set).sort();
});

const getEupBucketIndex = (price) => {
  const p = Number(price);
  if (isNaN(p) || p < 25000) return 0;
  if (p < 50000) return 1;
  if (p < 75000) return 2;
  if (p < 100000) return 3;
  if (p < 125000) return 4;
  if (p < 150000) return 5;
  if (p < 200000) return 6;
  return 7;
};

const monthlyYieldChartData = computed(() => {
  const pkgs = insightFilteredPackages.value || [];
  const monthlyPkgs = pkgs.filter(p => Number(p.days) >= 20 || String(p.package_name || '').toLowerCase().includes('bulan'));
  
  const datasets = [];
  const bucketYields = Array.from({ length: 8 }, () => []);
  
  yieldLandscapeProviders.value.forEach(prov => {
    const providerPoints = [];
    monthlyPkgs.forEach(pkg => {
      const pkgProv = (pkg.provider || '').toUpperCase().trim();
      if (pkgProv === prov) {
        const price = Number(pkg.price) || 0;
        const gb = parseFloat(pkg.gb) || 1;
        if (gb > 0 && price > 0) {
          const yieldVal = Number(pkg.yield_val) > 0 ? Number(pkg.yield_val) : Math.round(price / gb);
          const idx = getEupBucketIndex(price);
          
          if (isYieldProviderActive(prov)) {
            bucketYields[idx].push(yieldVal);
          }
          
          const idHash = parseInt(pkg.id || 0) || Math.abs(price + Math.round(gb * 10));
          const jitter = Math.sin(idHash * 997) * 0.25;
          
          providerPoints.push({
            x: idx + jitter,
            y: yieldVal,
            pkgName: pkg.package_name || 'Paket Data',
            provider: prov,
            price: price,
            gb: gb,
            days: pkg.days || 30
          });
        }
      }
    });
    
    if (providerPoints.length > 0) {
      datasets.push({
        label: prov,
        type: 'scatter',
        data: providerPoints,
        backgroundColor: getProviderColor(prov),
        borderColor: '#ffffff',
        borderWidth: 1,
        pointRadius: 6,
        pointHoverRadius: 8,
        hidden: !isYieldProviderActive(prov),
        order: 1
      });
    }
  });
  
  const medianPoints = [];
  bucketYields.forEach((yields, idx) => {
    if (yields.length > 0) {
      yields.sort((a, b) => a - b);
      const mid = Math.floor(yields.length / 2);
      const medianVal = yields.length % 2 === 0 ? (yields[mid - 1] + yields[mid]) / 2 : yields[mid];
      medianPoints.push({ x: idx, y: Math.round(medianVal) });
    }
  });
  
  if (medianPoints.length > 0) {
    datasets.unshift({
      label: 'Median per bucket',
      type: 'line',
      data: medianPoints,
      borderColor: '#64748b',
      borderDash: [6, 4],
      borderWidth: 2,
      pointRadius: 6,
      pointHoverRadius: 8,
      pointBackgroundColor: '#334155',
      pointBorderColor: '#ffffff',
      pointBorderWidth: 1.5,
      pointStyle: 'rectRot',
      fill: false,
      tension: 0.25,
      order: 0
    });
  }
  
  return { datasets };
});

const sachetYieldLabels = Array.from({ length: 19 }, (_, i) => `${i + 1}d`);

const sachetYieldChartData = computed(() => {
  const pkgs = insightFilteredPackages.value || [];
  const sachetPkgs = pkgs.filter(p => Number(p.days) < 20 && !String(p.package_name || '').toLowerCase().includes('bulan'));
  
  const datasets = [];
  const bucketYields = Array.from({ length: 19 }, () => []);
  
  yieldLandscapeProviders.value.forEach(prov => {
    const providerPoints = [];
    sachetPkgs.forEach(pkg => {
      const pkgProv = (pkg.provider || '').toUpperCase().trim();
      if (pkgProv === prov) {
        const price = Number(pkg.price) || 0;
        const gb = parseFloat(pkg.gb) || 1;
        const days = Number(pkg.days) || 1;
        if (gb > 0 && price > 0 && days >= 1 && days <= 19) {
          const yieldVal = Number(pkg.yield_val) > 0 ? Number(pkg.yield_val) : Math.round(price / gb);
          const idx = days - 1;
          
          if (isYieldProviderActive(prov)) {
            bucketYields[idx].push(yieldVal);
          }
          
          const idHash = parseInt(pkg.id || 0) || Math.abs(price + Math.round(gb * 10));
          const jitter = Math.cos(idHash * 883) * 0.25;
          
          providerPoints.push({
            x: idx + jitter,
            y: yieldVal,
            pkgName: pkg.package_name || 'Paket Data',
            provider: prov,
            price: price,
            gb: gb,
            days: days
          });
        }
      }
    });
    
    if (providerPoints.length > 0) {
      datasets.push({
        label: prov,
        type: 'scatter',
        data: providerPoints,
        backgroundColor: getProviderColor(prov),
        borderColor: '#ffffff',
        borderWidth: 1,
        pointRadius: 6,
        pointHoverRadius: 8,
        hidden: !isYieldProviderActive(prov),
        order: 1
      });
    }
  });
  
  const medianPoints = [];
  bucketYields.forEach((yields, idx) => {
    if (yields.length > 0) {
      yields.sort((a, b) => a - b);
      const mid = Math.floor(yields.length / 2);
      const medianVal = yields.length % 2 === 0 ? (yields[mid - 1] + yields[mid]) / 2 : yields[mid];
      medianPoints.push({ x: idx, y: Math.round(medianVal) });
    }
  });
  
  if (medianPoints.length > 0) {
    datasets.unshift({
      label: 'Median per bucket',
      type: 'line',
      data: medianPoints,
      borderColor: '#64748b',
      borderDash: [6, 4],
      borderWidth: 2,
      pointRadius: 6,
      pointHoverRadius: 8,
      pointBackgroundColor: '#334155',
      pointBorderColor: '#ffffff',
      pointBorderWidth: 1.5,
      pointStyle: 'rectRot',
      fill: false,
      tension: 0.25,
      order: 0
    });
  }
  
  return { datasets };
});

const getYieldChartOptions = (labels) => ({
  responsive: true,
  maintainAspectRatio: false,
  layout: {
    padding: { top: 15, right: 25, bottom: 10, left: 15 }
  },
  scales: {
    x: {
      type: 'linear',
      position: 'bottom',
      min: -0.5,
      max: labels.length - 0.5,
      title: {
        display: true,
        text: labels.length === 8 ? 'Slab EUP (Rp)' : 'Validity (hari)',
        color: '#334155',
        font: { family: "'Inter', sans-serif", weight: '700', size: 12 }
      },
      afterBuildTicks: (scale) => {
        scale.ticks = labels.map((_, i) => ({ value: i }));
      },
      ticks: {
        callback: function(value) {
          return labels[Math.round(value)] || '';
        },
        color: '#475569',
        font: { family: "'Inter', sans-serif", weight: '600', size: 11 },
        padding: 8
      },
      grid: {
        color: '#f1f5f9',
        drawBorder: false
      }
    },
    y: {
      beginAtZero: true,
      title: {
        display: true,
        text: 'Yield (Rp per GB)',
        color: '#334155',
        font: { family: "'Inter', sans-serif", weight: '700', size: 12 }
      },
      ticks: {
        callback: function(value) {
          return Number(value).toLocaleString('id-ID');
        },
        color: '#475569',
        font: { family: "'Inter', sans-serif", size: 11 },
        padding: 8
      },
      grid: {
        color: '#e2e8f0',
        borderDash: [2, 2],
        drawBorder: false
      }
    }
  },
  plugins: {
    legend: {
      display: false
    },
    tooltip: {
      backgroundColor: 'rgba(15, 23, 42, 0.95)',
      titleColor: '#f8fafc',
      bodyColor: '#e2e8f0',
      padding: 12,
      cornerRadius: 8,
      borderColor: '#334155',
      borderWidth: 1,
      displayColors: true,
      callbacks: {
        title: function(context) {
          const pt = context[0].raw;
          if (context[0].dataset.label === 'Median per bucket') {
            const idx = Math.round(pt.x);
            return `Median — ${labels[idx] || ''}`;
          }
          return `${pt.provider || context[0].dataset.label} — ${pt.pkgName || 'Paket Data'}`;
        },
        label: function(context) {
          const pt = context.raw;
          if (context.dataset.label === 'Median per bucket') {
            return `Median Yield: Rp ${Number(Math.round(pt.y)).toLocaleString('id-ID')} / GB`;
          }
          return [
            `Harga: Rp ${Number(pt.price || 0).toLocaleString('id-ID')} (${pt.gb} GB, ${pt.days} Hari)`,
            `Yield: Rp ${Number(Math.round(pt.y)).toLocaleString('id-ID')} / GB`
          ];
        }
      }
    }
  },
  interaction: {
    mode: 'nearest',
    intersect: true
  }
});

const insightChartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      mode: 'index',
      intersect: false,
      backgroundColor: 'rgba(255, 255, 255, 0.95)',
      titleColor: '#1e293b',
      bodyColor: '#475569',
      borderColor: '#e2e8f0',
      borderWidth: 1
    }
  },
  scales: {
    y: {
      border: { display: false },
      grid: { display: true, color: '#f1f5f9' },
      ticks: { color: '#64748b', font: { family: "'Inter', sans-serif", size: 10 } }
    },
    x: {
      border: { display: false },
      grid: { display: false },
      ticks: { color: '#64748b', font: { family: "'Inter', sans-serif", size: 10 }, maxRotation: 45, minRotation: 0 }
    }
  }
};

const marketAverages = computed(() => {
  let pkgs = marketFilteredPackages.value;
  if (!pkgs || pkgs.length === 0) return { yield: null, price: null, quota: null, validity: null };
  
  const provMap = {};
  pkgs.forEach(pkg => {
    const prov = pkg.provider;
    if (!provMap[prov]) provMap[prov] = { 
      sumYield: 0, sumPrice: 0, sumGb: 0, sumDays: 0, count: 0, packages: [] 
    };
    
    provMap[prov].sumYield += Number(pkg.yield_val);
    provMap[prov].sumPrice += Number(pkg.price);
    provMap[prov].sumGb += Number(pkg.gb);
    provMap[prov].sumDays += Number(pkg.days);
    provMap[prov].count += 1;
    provMap[prov].packages.push(pkg);
  });
  
  const averages = Object.keys(provMap).map(prov => {
    const data = provMap[prov];
    const packages = data.packages;
    
    // Compute min/max for Quota (gb)
    const sortedByGb = [...packages].sort((a,b) => Number(b.gb) - Number(a.gb));
    
    // Compute min/max for Price
    const sortedByPrice = [...packages].sort((a,b) => Number(b.price) - Number(a.price));
    
    // Compute min/max for Yield
    const sortedByYield = [...packages].sort((a,b) => Number(b.yield_val) - Number(a.yield_val));
    
    // Compute min/max for Days
    const sortedByDays = [...packages].sort((a,b) => Number(b.days) - Number(a.days));
    
    return {
      provider: prov,
      count: data.count,
      sumGb: data.sumGb,
      sumPrice: data.sumPrice,
      sumYield: data.sumYield,
      sumDays: data.sumDays,
      avgYield: data.sumYield / data.count,
      avgPrice: data.sumPrice / data.count,
      avgGb: data.sumGb / data.count,
      avgDays: data.sumDays / data.count,
      details: {
        quota: { max: sortedByGb[0], min: sortedByGb[sortedByGb.length - 1] },
        price: { max: sortedByPrice[0], min: sortedByPrice[sortedByPrice.length - 1] },
        yield: { max: sortedByYield[0], min: sortedByYield[sortedByYield.length - 1] },
        validity: { max: sortedByDays[0], min: sortedByDays[sortedByDays.length - 1] }
      }
    };
  });
  
  if (averages.length === 0) return { yield: null, price: null, quota: null, validity: null };
  
  const yieldSorted = [...averages].sort((a, b) => a.avgYield - b.avgYield);
  const priceSorted = [...averages].sort((a, b) => a.avgPrice - b.avgPrice);
  const gbSorted = [...averages].sort((a, b) => b.avgGb - a.avgGb);
  const daysSorted = [...averages].sort((a, b) => b.avgDays - a.avgDays);
  
  const bestYield = yieldSorted[0];
  const bestPrice = priceSorted[0];
  const bestGb = gbSorted[0];
  const bestDays = daysSorted[0];
  
  const getChartAxisInfo = (maxVal) => {
    if (!maxVal || isNaN(maxVal) || maxVal === 0) return { ticks: [0], maxTick: 1 };
    const rawStep = maxVal / 5;
    const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
    const normalizedStep = rawStep / Math.max(magnitude, 1);
    
    let step;
    if (normalizedStep < 1.5) step = 1 * magnitude;
    else if (normalizedStep < 3) step = 2 * magnitude;
    else if (normalizedStep < 7) step = 5 * magnitude;
    else step = 10 * magnitude;
    
    const ticks = [];
    let current = 0;
    while (current <= maxVal) {
      ticks.push(current);
      current += step;
    }
    if (ticks[ticks.length - 1] < maxVal) {
      ticks.push(ticks[ticks.length - 1] + step);
    }
    return { ticks, maxTick: ticks[ticks.length - 1] || 1 };
  };

  const maxYield = averages.length > 0 ? Math.max(...averages.map(i => i.avgYield)) : 0;
  const maxPrice = averages.length > 0 ? Math.max(...averages.map(i => i.avgPrice)) : 0;
  const maxGb = averages.length > 0 ? Math.max(...averages.map(i => i.avgGb)) : 0;
  const maxDays = averages.length > 0 ? Math.max(...averages.map(i => i.avgDays)) : 0;
  
  const yieldAxis = getChartAxisInfo(maxYield);
  const priceAxis = getChartAxisInfo(maxPrice);
  const gbAxis = getChartAxisInfo(maxGb);
  const daysAxis = getChartAxisInfo(maxDays);
  
  return {
    yield: { 
      provider: bestYield.provider, 
      value: `Rp${formatNumber(bestYield.avgYield, 0)}/GB`,
      axis: yieldAxis,
      list: yieldSorted.map(item => ({ 
        ...item,
        provider: item.provider, 
        value: `Rp${formatNumber(item.avgYield, 0)}/GB`,
        percent: (item.avgYield / yieldAxis.maxTick) * 100
      }))
    },
    price: { 
      provider: bestPrice.provider, 
      value: `Rp${formatNumber(bestPrice.avgPrice, 0)}`,
      axis: priceAxis,
      list: priceSorted.map(item => ({ 
        ...item,
        provider: item.provider, 
        value: `Rp${formatNumber(item.avgPrice, 0)}`,
        percent: (item.avgPrice / priceAxis.maxTick) * 100
      }))
    },
    quota: { 
      provider: bestGb.provider, 
      value: `${formatNumber(bestGb.avgGb, 1)} GB`,
      axis: gbAxis,
      list: gbSorted.map(item => ({ 
        ...item,
        provider: item.provider, 
        value: `${formatNumber(item.avgGb, 1)} GB`,
        percent: (item.avgGb / gbAxis.maxTick) * 100
      }))
    },
    validity: { 
      provider: bestDays.provider, 
      value: `${Math.round(bestDays.avgDays)} Hari`,
      axis: daysAxis,
      list: daysSorted.map(item => ({ 
        ...item,
        provider: item.provider, 
        value: `${Math.round(item.avgDays)} Hari`,
        percent: (item.avgDays / daysAxis.maxTick) * 100
      }))
    }
  };
});

const marketSummary = computed(() => {
  const pkgs = insightFilteredPackages.value;
  if (!pkgs || pkgs.length === 0) return [];
  
  const summaries = [];
  summaries.push(`${pkgs.length} Paket berhasil dianalisa`);
  
  const monthlyOffers = bestOffersMonthly(pkgs);
  const tier30to50 = monthlyOffers.find(o => o.label === '30K - 50K');
  if (tier30to50 && tier30to50.provider) {
    summaries.push(`${tier30to50.provider} unggul : Bulanan 30-50K`);
  }
  
  const validityOffers = bestOffersByValidity(pkgs);
  const harianProviders = validityOffers
    .filter(o => o.label.includes('day') && parseInt(o.label) <= 7)
    .map(o => o.provider);
  
  if (harianProviders.length > 0) {
    const counts = {};
    harianProviders.forEach(p => counts[p] = (counts[p] || 0) + 1);
    const topHarian = Object.keys(counts).reduce((a, b) => counts[a] > counts[b] ? a : b);
    summaries.push(`${topHarian} unggul : Harian`);
  }
  
  const weeklyProviders = validityOffers
    .filter(o => o.label.includes('day') && parseInt(o.label) > 7)
    .map(o => o.provider);
  if (weeklyProviders.length > 0) {
    const counts = {};
    weeklyProviders.forEach(p => counts[p] = (counts[p] || 0) + 1);
    const topWeekly = Object.keys(counts).reduce((a, b) => counts[a] > counts[b] ? a : b);
    summaries.push(`${topWeekly} mulai agresif di Weekly`);
  }
  
  const competitiveness = overallCompetitiveness.value;
  if (competitiveness.length > 0) {
    summaries.push(`${competitiveness[0].provider} paling kompetitif secara keseluruhan`);
  }
  
  return summaries;
});
const totalUsage = computed(() =>
  apiKeys.value.reduce((sum, k) => sum + k.usage_count, 0),
);

const totalFileSize = computed(() => {
  if (!form.images || form.images.length === 0) return 0;
  return form.images.reduce((total, file) => total + file.size, 0);
});

const fileSizePercentage = computed(() => {
  const maxBytes = 100 * 1024 * 1024; // 100MB
  const pct = (totalFileSize.value / maxBytes) * 100;
  return Math.min(100, Math.max(0, pct));
});

const formatBytes = (bytes) => {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
};

const extractionProgress = computed(() => {
  if (!activeSession.value || !activeSession.value.status) return 0;
  const match = activeSession.value.status.match(/\((\d+)\/(\d+)\)/);
  if (match) {
    const current = parseInt(match[1]);
    const total = parseInt(match[2]);
    if (total > 0) {
      return Math.min(90, Math.round((current / total) * 90));
    }
  }
  
  if (activeSession.value.status === 'pending') return 5;
  if (activeSession.value.status === 'processing') return 10;
  if (activeSession.value.status.includes('Mengekstrak data dari gambar')) return 15;
  if (activeSession.value.status === 'Menyusun insight & benchmarking...') return 95;
  
  return 100;
});

watch(extractionProgress, (newVal) => {
  nextTick(() => {
    anime({
      targets: '.anime-progress-bar',
      width: `${newVal}%`,
      duration: 800,
      easing: 'easeOutQuart'
    });
  });
});

// ─── Actions ────────────────────────────────────────────────────
const scrollToBottom = () => {
  nextTick(() => {
    if (chatContainer.value) {
      anime({
        targets: chatContainer.value,
        scrollTop: chatContainer.value.scrollHeight,
        duration: 600,
        easing: 'easeOutQuart'
      });
    }
  });
};

const parseMarkdown = (text) => {
  if (!text) return "";
  return marked.parse(text);
};

const showActiveUploadModal = ref(false);
const activeUploadMode = ref('image'); // 'image' or 'excel'

const processActiveUpload = () => {
  showActiveUploadModal.value = false;
  submit();
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

  form.transform((data) => {
    let ts = data.manual_timestamp;
    if (ts && ts instanceof Date) {
      ts = ts.getFullYear() + '-' + 
         String(ts.getMonth() + 1).padStart(2, '0') + '-' + 
         String(ts.getDate()).padStart(2, '0') + ' ' + 
         String(ts.getHours()).padStart(2, '0') + ':' + 
         String(ts.getMinutes()).padStart(2, '0') + ':' + 
         String(ts.getSeconds()).padStart(2, '0');
    }
    return {
      ...data,
      manual_timestamp: ts,
      pricelist_id: activeSessionId.value,
      is_append: !!activeSessionId.value,
    };
  }).post(route("scanner.store"), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: (page) => {
      form.reset();
      form.images = [];
      selectedRegions.value = [];
      if (fileInput.value) fileInput.value.value = "";
      if (!activeSessionId.value && page.props.pricelists.length > 0) {
        const newest = [...page.props.pricelists].sort(
          (a, b) => new Date(b.created_at) - new Date(a.created_at),
        )[0];
        activeSessionId.value = newest.id;
      }
      scrollToBottom();
    },
    onError: (errors) => {
      console.error("Form errors:", errors);
      alert("Error dari server: " + Object.values(errors).join("\n"));
    }
  });
};

const uploadDataForm = useForm({ data_file: null, manual_timestamp: "", location: "" });
const uploadDataRegion = ref("");
const uploadData = () => {
  if (!uploadDataForm.data_file) return;

  uploadDataForm.post(route("scanner.uploadData"), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: (page) => {
      uploadDataForm.reset();
      if (!activeSessionId.value && page.props.pricelists.length > 0) {
        const newest = [...page.props.pricelists].sort((a,b) => new Date(b.created_at) - new Date(a.created_at))[0];
        activeSessionId.value = newest.id;
      }
      scrollToBottom();
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Data berhasil diimpor',
        showConfirmButton: false,
        timer: 3000
      });
    },
  });
};

const bestOffersByValidity = (packages) => {
  if (!packages || packages.length === 0) return [];

  // Group by validity days ranges (exclude monthly)
  // Filter out invalid packages: price, gb, yield must all be > 0
  const sachet = packages.filter(
    (p) =>
      !p.category?.toLowerCase().includes("monthly") &&
      Number(p.days) < 28 &&
      Number(p.price) > 0 &&
      Number(p.gb) > 0 &&
      Number(p.yield_val) > 0,
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

  // Filter out invalid packages: price, gb, yield must all be > 0
  const monthly = packages.filter(
    (p) =>
      (p.category?.toLowerCase().includes("monthly") ||
      Number(p.days) >= 28) &&
      Number(p.price) > 0 &&
      Number(p.gb) > 0 &&
      Number(p.yield_val) > 0,
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

const generateAiInsight = async () => {
  if (aiInsightLoading.value) return;
  aiInsightLoading.value = true;
  aiInsightData.value = null;
  
  try {
    const provMap = {};
    marketFilteredPackages.value.forEach(pkg => {
      if (!provMap[pkg.provider]) {
        provMap[pkg.provider] = { price: 0, gb: 0, days: 0, yield_val: 0, count: 0 };
      }
      provMap[pkg.provider].price += Number(pkg.price);
      provMap[pkg.provider].gb += Number(pkg.gb);
      provMap[pkg.provider].days += Number(pkg.days);
      provMap[pkg.provider].yield_val += Number(pkg.yield_val);
      provMap[pkg.provider].count++;
    });
    
    const payload = Object.keys(provMap).map(prov => ({
      provider: prov,
      package_name: "Average " + marketSummaryFilter.value,
      price: Math.round(provMap[prov].price / provMap[prov].count),
      gb: provMap[prov].gb / provMap[prov].count,
      days: Math.round(provMap[prov].days / provMap[prov].count),
      yield_val: provMap[prov].yield_val / provMap[prov].count,
      category: marketSummaryFilter.value
    }));

    const response = await axios.post(route('scanner.aiInsight'), { packages: payload });
    aiInsightData.value = response.data.insight;
  } catch (error) {
    let msg = "Terjadi kesalahan saat memproses AI Insight.";
    if (error.response && error.response.data && error.response.data.error) {
      msg = error.response.data.error;
    }
    aiInsightData.value = "**Error:** " + msg;
  } finally {
    aiInsightLoading.value = false;
  }
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
    router.reload({ only: ["pricelists"], preserveScroll: true, preserveState: true });
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
    router.reload({ only: ["pricelists"], preserveScroll: true, preserveState: true });
  } catch (e) {
    showError(e, "Gagal mengulangi scan.");
  }
  retryLoading.value[id] = false;
};

// Editable Table States
const editablePackages = ref({});
const isEditingTable = ref({});
const savingTable = ref({});
const comparisonResults = ref({});
const unmatchedCsvResults = ref({});
const isComparing = ref({});

const editModalPkg = ref(null);
const editModalListId = ref(null);
const isSavingModal = ref(false);



const normalizeProvider = (provider) => {
  if (!provider) return '';
  let p = String(provider).toUpperCase().trim();
  if (p === 'SF') p = 'SMARTFREN';
  if (p === 'TSEL') p = 'TELKOMSEL';
  if (p === '3ID') p = '3';
  if (p === 'BYU') p = 'BY.U';
  return p;
};

const checkMatch = (key, val1, val2) => {
  if (key === 'provider') {
    return normalizeProvider(val1) === normalizeProvider(val2);
  }
  return val1 == val2; // loose equality for numbers/strings
};

const openEditModal = (pkg, listId) => {
  editModalPkg.value = JSON.parse(JSON.stringify(pkg));
  editModalListId.value = listId;
  
  if (editModalPkg.value.image_timestamp) {
    const parts = editModalPkg.value.image_timestamp.split(' ');
    editModalPkg.value.image_date = parts[0] || '';
    editModalPkg.value.image_time = parts[1] || '';
  } else {
    editModalPkg.value.image_date = '';
    editModalPkg.value.image_time = '';
  }
};

const closeEditModal = () => {
  editModalPkg.value = null;
  editModalListId.value = null;
};

const syncWithCsv = () => {
  if (!editModalPkg.value || !editModalListId.value) return;
  const res = comparisonResults.value[editModalListId.value]?.[editModalPkg.value.id];
  if (res && res.status !== 'matched' && res.status !== 'not_found' && res.csv_row) {
    editModalPkg.value.provider = res.csv_row.provider;
    editModalPkg.value.price = res.csv_row.price;
    editModalPkg.value.gb = res.csv_row.gb;
    editModalPkg.value.days = res.csv_row.days;
  }
};

const saveRowEdit = async () => {
  if (!editModalPkg.value || !editModalListId.value) return;
  isSavingModal.value = true;
  
  let combinedTimestamp = null;
  if (editModalPkg.value.image_date && editModalPkg.value.image_time) {
    combinedTimestamp = `${editModalPkg.value.image_date} ${editModalPkg.value.image_time}`;
  } else if (editModalPkg.value.image_date) {
    combinedTimestamp = editModalPkg.value.image_date;
  }
  editModalPkg.value.image_timestamp = combinedTimestamp;
  
  try {
    await axios.put(route("scanner.package.update", editModalPkg.value.id), {
      provider: editModalPkg.value.provider,
      package_name: editModalPkg.value.package_name,
      price: editModalPkg.value.price,
      gb: editModalPkg.value.gb,
      days: editModalPkg.value.days,
      image_timestamp: editModalPkg.value.image_timestamp,
    });
    
    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: 'success',
      title: 'Data berhasil disimpan',
      showConfirmButton: false,
      timer: 2000,
      background: '#1a1a1c',
      color: '#f3f4f6'
    });
    
    if (comparisonResults.value[editModalListId.value]?.[editModalPkg.value.id]) {
      const comp = comparisonResults.value[editModalListId.value][editModalPkg.value.id];
      if (comp.status !== 'not_found' && comp.csv_row) {
        const isMatch = checkMatch('provider', editModalPkg.value.provider, comp.csv_row.provider) &&
                checkMatch('price', editModalPkg.value.price, comp.csv_row.price) &&
                checkMatch('gb', editModalPkg.value.gb, comp.csv_row.gb) &&
                checkMatch('days', editModalPkg.value.days, comp.csv_row.days);
        if (isMatch) {
          comp.status = 'synced';
        } else {
          comp.status = 'price_mismatch';
        }
      }
    }
    
    closeEditModal();
    router.reload({ only: ["pricelists"], preserveScroll: true, preserveState: true });
  } catch (e) {
    showError(e, "Gagal menyimpan perubahan");
  } finally {
    isSavingModal.value = false;
  }
};

const uploadCsvForComparison = async (event, listId) => {
  const file = event.target.files[0];
  if (!file) return;

  const formData = new FormData();
  formData.append("csv_file", file);

  isComparing.value[listId] = true;
  try {
    const response = await axios.post(route("scanner.compareCsv", listId), formData, {
      headers: {
        "Content-Type": "multipart/form-data"
      }
    });
    if (response.data.success) {
      comparisonResults.value[listId] = response.data.results;
      unmatchedCsvResults.value[listId] = response.data.unmatched_csv;
      Swal.fire({
        toast: true,
        position: 'top-end',
        icon: 'success',
        title: 'Perbandingan berhasil (' + response.data.total_csv + ' baris CSV vs ' + response.data.total_db + ' baris Data)',
        showConfirmButton: false,
        timer: 3000
      });
    }
  } catch (e) {
    showError(e, "Gagal membandingkan CSV");
  } finally {
    isComparing.value[listId] = false;
    event.target.value = ""; // reset input
  }
};

const syncAllCsv = async (session) => {
  const listId = session.id;
  if (!comparisonResults.value[listId]) return;

  const updates = [];
  for (const pkg of session.packages) {
    const comp = comparisonResults.value[listId][pkg.id];
    if (comp && comp.status === 'price_mismatch' && comp.csv_row) {
      updates.push({
        id: pkg.id,
        provider: comp.csv_row.provider,
        price: comp.csv_row.price,
        gb: comp.csv_row.gb,
        days: comp.csv_row.days,
      });
    }
  }
  const new_packages = unmatchedCsvResults.value[listId] || [];

  if (updates.length === 0 && new_packages.length === 0) {
    Swal.fire({ icon: 'info', title: 'Info', text: 'Tidak ada data yang perlu disamakan.', background: '#1a1a1c', color: '#f3f4f6'});
    return;
  }

  try {
    isComparing.value[listId] = true;
    await axios.post(route('scanner.syncCsv', listId), {
      updates,
      new_packages
    });
    
    // update local comparisonResults status to matched
    if (comparisonResults.value[listId]) {
      for (const pkgId in comparisonResults.value[listId]) {
        const comp = comparisonResults.value[listId][pkgId];
        if (comp && comp.status === 'price_mismatch') {
          comp.status = 'synced';
        }
      }
    }
    
    // Refresh page or list
    router.reload({ only: ["pricelists"], preserveScroll: true, preserveState: true });
    
    // clear unmatched results only
    unmatchedCsvResults.value[listId] = null;

    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: 'success',
      title: 'Data berhasil disamakan!',
      showConfirmButton: false,
      timer: 3000,
      background: '#1a1a1c',
      color: '#f3f4f6'
    });
  } catch (e) {
    console.error(e);
    showError(e, "Gagal menyamakan data");
  } finally {
    isComparing.value[listId] = false;
  }
};

const clearFlags = (listId) => {
  if (!confirm("Hapus semua flag perbandingan untuk sesi ini?")) return;
  comparisonResults.value[listId] = null;
  unmatchedCsvResults.value[listId] = null;
  
  if (filters.value.flags && filters.value.flags.length > 0) {
    filters.value.flags = [];
  }
};

const showComparisonDetail = (pkg, csvResult) => {
  if (!csvResult) return;
  
  let htmlContent = '';
  if (csvResult.status === 'not_found') {
    htmlContent = `<p class="text-gray-300 text-sm mb-4 dark:text-slate-900">Data hasil AI ini tidak memiliki pasangan yang cocok di dalam file CSV Ground Truth yang di-upload.</p>`;
  } else {
    const csv = csvResult.csv_row;
    htmlContent = `
    <div class="overflow-x-auto text-left mt-2 border border-slate-200 rounded-lg">
      <table class="w-full text-sm text-slate-700 divide-y divide-slate-200 dark:text-slate-900">
        <thead>
          <tr class="bg-slate-50 text-slate-700 font-bold dark:text-slate-900">
            <th class="p-3">Atribut</th>
            <th class="p-3">Hasil AI</th>
            <th class="p-3">Data CSV</th>
            <th class="p-3 text-center">Status</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-200 bg-white font-medium">
          <tr>
            <td class="p-3 font-bold text-slate-800 dark:text-slate-900">Provider</td>
            <td class="p-3">${pkg.provider}</td>
            <td class="p-3">${csv.provider}</td>
            <td class="p-3 text-center">✅</td>
          </tr>
          <tr>
            <td class="p-3 font-bold text-slate-800 dark:text-slate-900">Kuota (GB)</td>
            <td class="p-3">${pkg.gb}</td>
            <td class="p-3">${csv.gb}</td>
            <td class="p-3 text-center">✅</td>
          </tr>
          <tr>
            <td class="p-3 font-bold text-slate-800 dark:text-slate-900">Harga (Rp)</td>
            <td class="p-3">${Number(pkg.price).toLocaleString('id-ID')}</td>
            <td class="p-3">${Number(csv.price).toLocaleString('id-ID')}</td>
            <td class="p-3 text-center">${pkg.price == csv.price ? '✅' : '❌'}</td>
          </tr>
          <tr>
            <td class="p-3 font-bold text-slate-800 dark:text-slate-900">Masa Aktif</td>
            <td class="p-3">${pkg.days} Hari</td>
            <td class="p-3">${csv.days} Hari</td>
            <td class="p-3 text-center">${pkg.days == csv.days ? '✅' : '❌'}</td>
          </tr>
        </tbody>
      </table>
    </div>
    `;
  }

  Swal.fire({
    title: 'Detail Perbandingan',
    html: htmlContent,
    background: '#ffffff',
    color: '#1e293b',
    showCancelButton: true,
    confirmButtonColor: '#2563eb',
    cancelButtonColor: '#64748b',
    confirmButtonText: 'Edit Data',
    cancelButtonText: 'Tutup',
    width: '600px'
  }).then((result) => {
    if (result.isConfirmed) {
      if (activeSession.value && !isEditingTable.value[activeSession.value.id]) {
        toggleEditTable(activeSession.value.id, activeSession.value.packages);
      }
    }
  });
};

const toggleEditTable = (listId, packages) => {
  if (isEditingTable.value[listId]) {
    isEditingTable.value[listId] = false;
    selectedEditRows.value[listId] = [];
    editingPackageIds.value[listId] = [];
  } else {
    editablePackages.value[listId] = JSON.parse(JSON.stringify(packages));
    editingPackageIds.value[listId] = packages.map(p => p.id).filter(id => id);
    isEditingTable.value[listId] = true;
    activeTables.value[listId] = true;
    selectedEditRows.value[listId] = [];
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

const toggleSelectAllEdit = (listId, event) => {
  if (event.target.checked) {
    selectedEditRows.value[listId] = editablePackages.value[listId].map((_, i) => i);
  } else {
    selectedEditRows.value[listId] = [];
  }
};

const deleteSelectedRows = (listId) => {
  const selected = selectedEditRows.value[listId] || [];
  if (selected.length === 0) return;
  // Delete in reverse order to keep indices valid
  const sortedSelected = [...selected].sort((a, b) => b - a);
  sortedSelected.forEach(idx => {
    editablePackages.value[listId].splice(idx, 1);
  });
  selectedEditRows.value[listId] = [];
};

const savePackages = async (listId) => {
  savingTable.value[listId] = true;
  try {
    const list = props.pricelists.find(p => p.id === listId);
    const originalList = list ? list.packages : [];
    const editedIdsSet = new Set(editingPackageIds.value[listId] || []);
    
    // Packages that were NOT in the edit view (should be preserved)
    const preservedPackages = originalList.filter(p => !editedIdsSet.has(p.id));
    
    const finalPackages = [...preservedPackages, ...editablePackages.value[listId]];

    await axios.put(route("scanner.packages.update", listId), {
      packages: finalPackages,
    });
    isEditingTable.value[listId] = false;
    selectedEditRows.value[listId] = [];
    editingPackageIds.value[listId] = [];
    router.reload({ only: ["pricelists"], preserveScroll: true, preserveState: true });
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

// Baseline Editable Table States
const isEditingBaseline = ref(false);
const editableBaselineProducts = ref(JSON.parse(JSON.stringify(props.baselineProducts || [])));
const savingBaseline = ref(false);

const toggleEditBaselineTable = () => {
  if (isEditingBaseline.value) {
    isEditingBaseline.value = false;
  } else {
    editableBaselineProducts.value = JSON.parse(JSON.stringify(baselineProducts.value));
    isEditingBaseline.value = true;
  }
};

const addEmptyBaselineRow = () => {
  editableBaselineProducts.value.push({
    criteria: "HARIAN",
    provider: "TSEL",
    package_name: "",
    rbp_vori: null,
    rbp_rebuy: null,
    rbp_inject: null,
    price: 0,
    quota_s: 0,
    quota_e: 0,
    quota_a: 0,
    days: 1,
  });
};

const deleteBaselineRow = (index) => {
  editableBaselineProducts.value.splice(index, 1);
};

const saveBaselineProducts = async () => {
  savingBaseline.value = true;
  try {
    await axios.post(route("baseline.bulkUpdate"), {
      packages: editableBaselineProducts.value,
    });
    router.reload({ only: ['baselineProducts'] });
    Swal.fire({
      toast: true,
      position: 'top-end',
      icon: 'success',
      title: 'Data Baseline berhasil disimpan',
      showConfirmButton: false,
      timer: 3000
    });
  } catch (e) {
    showError(e, "Gagal menyimpan data baseline");
  }
  savingBaseline.value = false;
};

const openImage = (url) => {
  window.open("/storage/" + url, "_blank");
};

const savePromptAndRetry = async (list) => {
  const editData = editingPrompt.value[list.id];
  if (!editData || !editData.messageId) {
    showNotification('error', "Tidak dapat menemukan pesan awal untuk diedit.");
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
    router.reload({ only: ["pricelists"], preserveScroll: true, preserveState: true });
  } catch (e) {
    showError(e, "Gagal menyimpan prompt & mengulangi scan.");
  }
  retryLoading.value[list.id] = false;
};

const renameSessionId = ref(null);
const renameTitle = ref("");

const vFocus = {
  mounted: (el) => {
    el.focus();
    // Select all text when focusing
    el.select();
  }
};

const startRename = (list) => {
  renameSessionId.value = list.id;
  renameTitle.value = list.filename;
};

const saveRename = async (list) => {
  // Prevent double calls from blur + enter
  if (renameSessionId.value !== list.id) return;

  const newName = renameTitle.value.trim();
  
  // Hide input immediately so user is never stuck
  renameSessionId.value = null;

  if (!newName || newName === list.filename) {
    return;
  }

  try {
    await axios.put(route("scanner.rename", list.id), {
      filename: newName,
    });
    router.reload({ only: ["pricelists"], preserveScroll: true, preserveState: true });
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
      "Menyusun insight & benchmarking...",
    ].includes(l.status) || (l.status && l.status.includes("Mengekstrak data dari gambar"))
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

// ── Status mesin AI (desktop) ────────────────────────────────────────────
// Aplikasi desktop menjalankan FastAPI sebagai proses anak. Kalau mesin itu
// mati, semua ekstraksi gagal tanpa penjelasan - jadi tampilkan statusnya.
const systemHealth = ref(null);
const restartingEngine = ref(false);
let healthTimer = null;

const fetchHealth = async () => {
  try {
    const { data } = await axios.get("/api/system/health");
    systemHealth.value = data;
  } catch (e) {
    systemHealth.value = null;
  }
};

const restartEngine = async () => {
  if (restartingEngine.value) return;
  restartingEngine.value = true;
  try {
    const { data } = await axios.post("/api/system/restart", { process: "fastapi" });
    showNotification("success", data.message);
  } catch (e) {
    showError(e, "Gagal me-restart mesin AI.");
  } finally {
    restartingEngine.value = false;
    fetchHealth();
  }
};

onMounted(() => {
  scrollToBottom();
  fetchKeys();
  pollStatus();
  fetchTrendData();
  fetchHealth();
  healthTimer = setInterval(fetchHealth, 15000);

  // Global button micro-animation
  document.addEventListener('mousedown', (e) => {
    const btn = e.target.closest('button, .anime-btn');
    if (btn && !btn.disabled) {
      anime({
        targets: btn,
        scale: [1, 0.95],
        duration: 150,
        easing: 'easeOutQuad',
        direction: 'alternate'
      });
    }
  });
});

watch(trendDateRange, () => {
  fetchTrendData();
});
watch([trendCircle, trendRegion, trendBranch], () => {
  fetchTrendData();
});
watch(trendFiles, () => {
  fetchTrendData();
}, { deep: true });
onUnmounted(() => {
  clearTimeout(pollTimer);
  clearInterval(healthTimer);
});
</script>

<template>
  <Head title="VIPER" />
  <!-- Anime.js Global Loading Bar -->
  <div id="global-loader" class="fixed top-0 left-0 h-1 bg-theme-brand-primary z-50 w-0"></div>
  <div
    class="h-screen flex bg-theme-page text-theme-text-primary font-sans overflow-hidden transition-colors duration-200"
  >
    <!-- SIDEBAR -->
    <div
      :class="sidebarOpen ? 'w-72' : 'w-0 opacity-0'"
      class="flex-shrink-0 bg-theme-brand-primary text-white flex flex-col transition-all duration-300 overflow-hidden border-r border-theme-brand-primary shadow-sm"
    >
      <div
        class="h-16 px-4 border-b border-white/10 flex items-center justify-between shrink-0"
      >
        <div class="flex items-center gap-2">
          <!-- Viper snake SVG -->
          <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 2C8.13 2 5 5.13 5 9c0 1.96.8 3.73 2.1 5.02L9.5 16.5v2C9.5 19.88 10.62 21 12 21s2.5-1.12 2.5-2.5v-2l2.4-2.48C18.2 12.73 19 10.96 19 9c0-3.87-3.13-7-7-7zm0 2c2.76 0 5 2.24 5 5 0 1.34-.55 2.57-1.45 3.48L13 15.05v3.45c0 .55-.45 1-1 1s-1-.45-1-1v-3.45l-2.55-2.57C7.55 11.57 7 10.34 7 9c0-2.76 2.24-5 5-5zm-2 3.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm4 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/>
          </svg>
          <h2 class="text-xl font-bold text-white tracking-wide">
            VIPER
          </h2>
        </div>
        <div class="flex items-center space-x-1">
          <button @click="toggleDarkMode" aria-label="Toggle Dark Mode" class="p-1 hover:bg-white/10 rounded-lg transition text-white/70 hover:text-white active:scale-[0.98] transition-transform">
            <svg v-if="isDark" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z" />
            </svg>
          </button>
          <button
            @click="sidebarOpen = false"
            class="p-1 hover:bg-white/10 rounded-lg transition text-white/70 hover:text-white active:scale-[0.98] transition-transform"
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
      </div>
      <!-- Status & Input API Key -->
      <div class="p-3 border-b border-slate-200/80 dark:border-slate-700 space-y-4">
        <!-- Status Usage -->
        <div class="bg-white/5 rounded-xl p-3 border border-white/10">
          <div class="text-xs text-white mb-1 font-semibold">
            Kapasitas Model
          </div>
          <div class="flex items-end justify-between">
            <div>
              <div class="text-xl font-bold text-white">
                {{
                  Math.max(
                    0,
                    activeKeyCount * 1500 - totalUsage,
                  )
                }}
              </div>
              <div class="text-[10px] text-white/80 font-medium">
                Permintaan tersisa
              </div>
            </div>
            <div class="text-right">
              <div class="text-sm font-bold text-white">
                {{ activeKeyCount }} Key
              </div>
              <div class="text-[10px] text-white/80 font-medium">Aktif</div>
            </div>
          </div>
          <!-- Progress Bar -->
          <div
            class="w-full bg-white/30 rounded-full h-1.5 mt-2 overflow-hidden"
            title="Persentase Penggunaan"
          >
            <div
              class="bg-white h-1.5 rounded-full transition-all duration-500"
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
            class="w-full bg-white/10 border border-white/20 rounded-lg px-3 py-2 text-xs text-white placeholder-white/50 focus:outline-none focus:border-theme-brand-secondary focus:ring-1 focus:ring-theme-brand-secondary transition"
            required
          />
          <button
            type="submit"
            :disabled="keyLoading"
            class="bg-white/10 hover:bg-white/20 border border-white/20 text-white rounded-lg px-3 py-2 transition flex items-center justify-center shrink-0 disabled:opacity-50 active:scale-[0.98] transition-transform"
          >
            <svg
              v-if="keyLoading"
              class="w-4 h-4 animate-spin text-white"
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
              class="w-4 h-4 text-white"
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
          class="w-full flex items-center justify-center gap-2 px-4 py-2 bg-theme-brand-secondary hover:bg-theme-brand-secondary/90 text-white text-sm font-medium rounded-lg transition active:scale-[0.98] transition-transform"
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
      </div>

      <!-- History List -->
      <div
        class="flex-1 overflow-y-auto px-3 pb-4 space-y-1 custom-scrollbar"
      >
        <div
          class="text-xs font-semibold text-white/50 uppercase tracking-wider mb-2 mt-4 px-2"
        >
          Terbaru
        </div>

        <div
          v-for="list in sortedPricelists"
          :key="list.id"
          class="sidebar-item group flex items-center justify-between p-2 rounded-lg cursor-pointer transition text-sm opacity-0"
          :class="
            activeSessionId === list.id
              ? 'bg-theme-brand-accent/20 text-white font-medium border-l-2 border-theme-brand-accent shadow-sm'
              : 'text-white/70 hover:bg-white/10 hover:text-white border-l-2 border-transparent'
          "
          @click="activeSessionId = list.id"
        >
          <div class="flex items-center gap-3 overflow-hidden flex-1">
            <svg
              class="w-4 h-4 shrink-0"
              :class="activeSessionId === list.id ? 'text-white' : 'text-white/50'"
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
              v-focus
              class="bg-white text-slate-900 text-sm px-2 py-1 rounded border border-blue-500 outline-none w-full shadow-inner"
            />
            <span v-else class="truncate">{{ list.filename }}</span>
          </div>

          <div
            v-if="renameSessionId !== list.id"
            class="flex items-center opacity-0 group-hover:opacity-100 transition shrink-0 ml-2"
          >
            <button
              @click.stop="startRename(list)"
              class="p-1 hover:bg-white/20 rounded text-white/50 hover:text-white active:scale-[0.98] transition-transform"
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
              class="p-1 hover:bg-semantic-danger/20 rounded text-white/50 hover:text-semantic-danger active:scale-[0.98] transition-transform"
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

      <!-- Status mesin AI (FastAPI) + versi aplikasi -->
      <div class="px-4 py-3 border-t border-white/10 bg-primary space-y-1.5">
        <div class="flex items-center justify-between gap-2">
          <div class="flex items-center gap-2 min-w-0">
            <span
              class="w-2 h-2 rounded-full shrink-0"
              :class="systemHealth?.fastapi?.running ? 'bg-emerald-400' : 'bg-rose-400 animate-pulse'"
            ></span>
            <span
              class="text-xs text-white/70 truncate"
              :title="systemHealth?.fastapi?.detail ?? 'Memeriksa status mesin AI...'"
            >
              Mesin AI: {{ systemHealth?.fastapi?.running ? "Aktif" : "Mati" }}
            </span>
          </div>
          <button
            @click="restartEngine"
            :disabled="restartingEngine"
            title="Restart mesin AI (FastAPI)"
            class="text-[11px] px-2 py-1 rounded bg-white/10 hover:bg-white/20 text-white/80 hover:text-white transition disabled:opacity-50 disabled:cursor-not-allowed shrink-0"
          >
            {{ restartingEngine ? "..." : "Restart" }}
          </button>
        </div>
        <div v-if="systemHealth?.version" class="text-[11px] text-white/40">
          Versi {{ systemHealth.version }}
        </div>
      </div>

      <div
        class="p-4 border-t border-white/10 text-xs text-white/60 flex justify-between items-center bg-primary"
      >
        <span>API Keys: {{ activeKeyCount }} active</span>
        <span title="Total Usage">{{ totalUsage }} reqs</span>
      </div>
    </div>

    <!-- MAIN AREA -->
    <div class="flex-1 flex flex-col h-screen relative bg-theme-page transition-colors duration-200">
      <!-- Topbar (Mobile Hamburger) -->
      <div
        class="h-16 flex items-center px-4 border-b border-theme-border-subtle shrink-0 bg-theme-surface"
      >
        <button
          v-if="!sidebarOpen"
          @click="sidebarOpen = true"
          class="p-2 hover:bg-black/5 dark:hover:bg-white/10 rounded-lg text-theme-text-secondary transition hover:text-theme-text-primary active:scale-[0.98] transition-transform"
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
            class="text-sm text-theme-text-primary font-medium"
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
                class="text-4xl font-extrabold mb-2 text-theme-text-primary tracking-tight flex justify-center items-center gap-3"
              >
                <svg class="w-10 h-10 text-theme-brand-primary dark:text-theme-brand-secondary" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M12 2C8.13 2 5 5.13 5 9c0 1.96.8 3.73 2.1 5.02L9.5 16.5v2C9.5 19.88 10.62 21 12 21s2.5-1.12 2.5-2.5v-2l2.4-2.48C18.2 12.73 19 10.96 19 9c0-3.87-3.13-7-7-7zm0 2c2.76 0 5 2.24 5 5 0 1.34-.55 2.57-1.45 3.48L13 15.05v3.45c0 .55-.45 1-1 1s-1-.45-1-1v-3.45l-2.55-2.57C7.55 11.57 7 10.34 7 9c0-2.76 2.24-5 5-5zm-2 3.5a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3zm4 0a1.5 1.5 0 1 0 0 3 1.5 1.5 0 0 0 0-3z"/>
                </svg>
                VIPER
              </h1>
              <p class="text-theme-brand-secondary font-medium mb-3 text-lg">Vision-based Internet Package Extraction & Recognition</p>
              <p class="text-theme-text-secondary text-md">
                Pilih modul dashboard dan unggah file untuk dianalisis.
              </p>
            </div>

            <!-- Tabs -->
            <div class="flex justify-center mb-8">
              <div class="bg-theme-secondary p-1 rounded-xl inline-flex shadow-inner border border-theme-border-subtle">
                <button @click="inputType = 'scan'" :class="inputType === 'scan' ? 'bg-theme-surface text-theme-brand-primary shadow-md' : 'text-theme-text-muted hover:text-theme-text-primary hover:bg-black/5 dark:hover:bg-white/5'" class="px-6 py-2.5 rounded-lg text-sm font-bold transition-all duration-300 active:scale-[0.98] transition-transform">
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                    Scan Gambar (AI)
                  </div>
                </button>
                <button @click="inputType = 'data'" :class="inputType === 'data' ? 'bg-theme-surface text-theme-brand-primary shadow-md' : 'text-theme-text-muted hover:text-theme-text-primary hover:bg-black/5 dark:hover:bg-white/5'" class="px-6 py-2.5 rounded-lg text-sm font-bold transition-all duration-300 active:scale-[0.98] transition-transform">
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                    Input Data Manual
                  </div>
                </button>
                <button @click="inputType = 'whatsapp'" :class="inputType === 'whatsapp' ? 'bg-theme-surface text-emerald-600 dark:text-emerald-400 shadow-md' : 'text-theme-text-muted hover:text-theme-text-primary hover:bg-black/5 dark:hover:bg-white/5'" class="px-6 py-2.5 rounded-lg text-sm font-bold transition-all duration-300 active:scale-[0.98] transition-transform">
                  <div class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-emerald-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12.012 2c-5.506 0-9.989 4.478-9.99 9.984 0 1.762.459 3.48 1.332 5.001l-1.416 5.176 5.297-1.389c1.474.803 3.146 1.226 4.774 1.227h.004c5.505 0 9.988-4.478 9.989-9.984 0-2.668-1.037-5.176-2.922-7.062-1.886-1.886-4.394-2.924-7.068-2.924zm5.7 14.167c-.244.688-1.42 1.314-1.961 1.378-.517.061-1.189.096-3.415-.815-2.845-1.163-4.664-4.043-4.806-4.234-.141-.191-1.149-1.528-1.149-2.914 0-1.386.726-2.068.984-2.35.258-.282.563-.353.751-.353.188 0 .376.002.54.01.176.009.412-.067.645.493.245.588.834 2.033.905 2.179.071.146.118.318.024.506-.094.188-.141.306-.282.471-.141.165-.297.369-.424.496-.141.141-.288.295-.124.577.165.282.732 1.205 1.571 1.954 1.079.963 1.989 1.261 2.271 1.402.282.141.447.118.612-.071.165-.188.705-.823.893-1.105.188-.282.376-.235.635-.141.258.094 1.644.775 1.926.916.282.141.47.211.54.329.07.117.07.681-.175 1.369z"/></svg>
                    WhatsApp Bot (AI)
                  </div>
                </button>
              </div>
            </div>

            <!-- WhatsApp Bot Monitor View -->
            <div v-show="inputType === 'whatsapp'" class="w-full mb-12">
              <WhatsAppMonitor />
            </div>

            <!-- Drag & Drop Zone (Input File Image) -->
            <div v-show="inputType === 'scan'" class="w-full relative mb-12">
              <label
                class="w-full min-h-[320px] h-auto border-2 border-dashed border-theme-border-default hover:border-theme-brand-primary hover:bg-theme-brand-primary/5 focus-within:border-theme-brand-primary focus-within:ring-4 focus-within:ring-theme-brand-primary/20 transition-all rounded-3xl flex flex-col items-center justify-center cursor-pointer group shadow-sm relative overflow-hidden bg-theme-surface"
                :class="{ 'cursor-default pointer-events-none': form.images.length > 0 }"
              >
                <input
                  type="file"
                  accept="image/*,.zip"
                  multiple
                  @change="(e) => { 
                    form.images = Array.from(e.target.files); 
                    form.locations = Array(form.images.length).fill('');
                    selectedRegions = Array(form.images.length).fill('');
                  }"
                  class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                  :disabled="form.processing || form.images.length > 0"
                />

                <!-- Loading Overlay when Processing -->
                <div
                  v-if="form.processing"
                  class="absolute inset-0 bg-white/90 dark:bg-slate-900/90 backdrop-blur flex flex-col items-center justify-center z-20 transition-all"
                >
                  <div class="w-12 h-12 border-4 border-secondary dark:border-secondary border-t-transparent rounded-full animate-spin mb-4"></div>
                  <span class="font-semibold text-primary dark:text-indigo-600 text-lg tracking-wide animate-pulse">Mengunggah & Membuat Sesi...</span>
                </div>

                <!-- Staging State (Files Selected) -->
                <div v-if="form.images.length > 0" class="w-full flex-1 bg-slate-50 dark:bg-slate-900 flex flex-col items-center justify-center p-8 z-10">
                  <div class="w-16 h-16 bg-green-500/10 rounded-full flex items-center justify-center mb-4">
                    <svg class="anime-svg-draw w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                  </div>
                  <h3 class="text-xl font-bold text-slate-800 dark:text-slate-900 mb-2">{{ form.images.length }} File Terpilih</h3>
                  
                  <!-- Progress Bar -->
                  <div class="w-full max-w-md mt-0 mb-4 pointer-events-auto">
                    <div class="flex justify-between text-sm text-slate-500 mb-2 dark:text-slate-900">
                      <span>Ukuran: {{ formatBytes(totalFileSize) }}</span>
                      <span>Batas: 100 MB</span>
                    </div>
                    <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                      <div 
                        class="h-full rounded-full transition-all duration-500"
                        :class="fileSizePercentage >= 100 ? 'bg-theme-semantic-danger' : 'bg-theme-brand-primary'"
                        :style="{ width: fileSizePercentage + '%' }"
                      ></div>
                    </div>
                    <p v-if="fileSizePercentage >= 100" class="text-red-500 text-sm mt-2 text-center font-medium">Ukuran melebihi batas maksimal!</p>
                  </div>

                  <!-- File List with Location Selectors -->
                  <div class="w-full max-w-md mb-2 flex flex-col pointer-events-auto">
                    <label class="text-xs text-slate-500 mb-1 dark:text-slate-900 text-center">Pilih Lokasi (Opsional)</label>
                    <div class="w-full max-h-40 overflow-y-auto pr-2 bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700 p-2 shadow-inner">
                      <div v-for="(file, index) in form.images" :key="index" class="flex flex-col mb-3 pb-3 border-b border-slate-100 dark:border-slate-700 last:mb-0 last:pb-0 last:border-0">
                        <div class="text-sm font-medium text-slate-700 dark:text-slate-300 truncate mb-1" :title="file.name">
                          {{ file.name }}
                        </div>
                        <div class="flex flex-col sm:flex-row gap-2 w-full mt-0">
                        <select v-model="selectedRegions[index]" @change="form.locations[index] = ''" class="flex-1 text-xs bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded px-2 py-1.5 focus:ring-primary focus:border-primary">
                          <option value="">-- Pilih Region --</option>
                          <option v-for="group in branchOptions" :key="group.group" :value="group.group">{{ group.group }}</option>
                        </select>
                        <div class="flex-1 w-full">
                          <input 
                            type="text"
                            v-model="form.locations[index]" 
                            :list="'branch-list-' + index" 
                            :disabled="!selectedRegions[index]"
                            placeholder="-- Ketik / Pilih Branch --"
                            class="w-full text-xs bg-slate-50 dark:bg-slate-900 border border-slate-300 dark:border-slate-600 rounded px-2 py-1.5 focus:ring-primary focus:border-primary disabled:opacity-50 disabled:cursor-not-allowed"
                          >
                          <datalist :id="'branch-list-' + index">
                            <template v-if="selectedRegions[index]">
                              <option v-for="opt in branchOptions.find(g => g.group === selectedRegions[index])?.options || []" :key="opt" :value="opt"></option>
                            </template>
                          </datalist>
                        </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Timestamp Override -->
                  <div class="w-full max-w-xs mt-1 mb-2 flex flex-col pointer-events-auto">
                    <label class="text-xs text-slate-500 mb-1 dark:text-slate-900">Atur Waktu (Opsional)</label>
                    <input type="date" v-model="form.manual_timestamp" class="w-full bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-700 rounded-lg text-sm text-slate-800 px-3 py-2 focus:ring-primary dark:focus:ring-indigo-500 focus:border-primary dark:focus:border-indigo-500 text-center shadow-sm">
                  </div>

                  <div class="flex gap-4 mt-3 pointer-events-auto">
                    <button 
                      @click.stop="() => { form.images = []; form.locations = []; selectedRegions = []; }" 
                      class="px-5 py-2.5 rounded-xl border border-theme-border-default text-theme-text-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors font-medium active:scale-[0.98] transition-transform"
                    >
                      Batal
                    </button>
                    <button 
                      @click.stop="submit" 
                      :disabled="fileSizePercentage >= 100"
                      class="px-6 py-2.5 rounded-xl bg-theme-brand-primary hover:bg-theme-brand-primary/90 text-white shadow-sm transition-all font-semibold disabled:opacity-50 disabled:cursor-not-allowed active:scale-[0.98] transition-transform"
                    >
                      Proses File
                    </button>
                  </div>
                </div>

                <!-- Default State (No Files) -->
                <template v-else>
                  <div class="w-20 h-20 bg-primary/20 dark:bg-indigo-500/10 rounded-full flex items-center justify-center mb-5 group-hover:scale-110 group-hover:shadow-[0_0_30px_rgba(255,212,0,0.4)] dark:group-hover:shadow-[0_0_30px_rgba(99,102,241,0.2)] transition-all group-hover:bg-yellow-100 dark:group-hover:bg-indigo-500/20">
                    <svg class="anime-svg-draw w-10 h-10 text-theme-brand-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                  </div>
                  <span class="text-xl font-bold text-theme-text-primary mb-2 group-hover:text-theme-brand-primary transition-colors">Pilih atau Tarik Gambar / ZIP ke Sini</span>
                  <span class="text-sm text-theme-text-secondary bg-theme-page px-3 py-1 rounded-full border border-theme-border-subtle">Mendukung format JPG, PNG, dan ZIP</span>
                </template>
              </label>
            </div>
            
            <!-- Input Data (CSV/Excel) -->
            <div v-show="inputType === 'data'" class="w-full relative mb-12">
              <label
                class="w-full min-h-[320px] h-auto border-2 border-dashed border-theme-border-default hover:border-theme-brand-secondary hover:bg-theme-brand-secondary/5 focus-within:border-theme-brand-secondary focus-within:ring-4 focus-within:ring-theme-brand-secondary/20 transition-all rounded-3xl flex flex-col items-center justify-center cursor-pointer group shadow-sm relative overflow-hidden bg-theme-surface"
                :class="{ 'cursor-default pointer-events-none': uploadDataForm.data_file }"
              >
                <input
                  type="file"
                  accept=".csv,.txt,.xlsx,.xls"
                  @change="(e) => { uploadDataForm.data_file = e.target.files[0]; }"
                  class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"
                  :disabled="uploadDataForm.processing || uploadDataForm.data_file"
                />

                <!-- Loading Overlay when Processing -->
                <div
                  v-if="uploadDataForm.processing"
                  class="absolute inset-0 bg-white/90 dark:bg-slate-900/90 backdrop-blur flex flex-col items-center justify-center z-20 transition-all"
                >
                  <div class="w-12 h-12 border-4 border-secondary dark:border-secondary border-t-transparent rounded-full animate-spin mb-4"></div>
                  <span class="font-semibold text-secondary text-lg tracking-wide animate-pulse">Mengimpor Data...</span>
                </div>

                <!-- Staging State (File Selected) -->
                <div v-if="uploadDataForm.data_file" class="w-full flex-1 bg-slate-50 dark:bg-slate-900 flex flex-col items-center justify-center p-8 z-10">
                  <div class="w-16 h-16 bg-theme-brand-primary/10 rounded-full flex items-center justify-center mb-4">
                    <svg class="anime-svg-draw w-8 h-8 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                  </div>
                  <h3 class="text-xl font-bold text-slate-800 dark:text-slate-900 mb-2">{{ uploadDataForm.data_file.name }}</h3>
                  
                  <!-- Timestamp Override -->
                  <div class="w-full max-w-xs mt-1 mb-2 flex flex-col pointer-events-auto">
                    <label class="text-[11px] text-theme-text-secondary mb-1">Atur Waktu (Opsional)</label>
                    <input type="date" v-model="uploadDataForm.manual_timestamp" class="w-full bg-theme-surface border border-theme-border-default rounded-lg text-sm text-theme-text-primary px-3 py-2 focus:ring-theme-brand-secondary focus:border-theme-brand-secondary text-center shadow-sm">
                  </div>
                  
                  <div class="w-full max-w-xs mb-2 flex flex-col pointer-events-auto">
                    <label class="text-[11px] text-theme-text-secondary mb-1">Pilih Lokasi (Opsional)</label>
                    <div class="flex flex-col sm:flex-row gap-2 w-full mt-0">
                      <select v-model="uploadDataRegion" @change="uploadDataForm.location = ''" class="flex-1 text-sm bg-theme-surface border border-theme-border-default rounded-lg px-2 py-2 focus:ring-theme-brand-secondary focus:border-theme-brand-secondary text-theme-text-primary shadow-sm">
                        <option value="">-- Pilih Region --</option>
                        <option v-for="group in branchOptions" :key="'ud_' + group.group" :value="group.group">{{ group.group }}</option>
                      </select>
                      <div class="flex-1 w-full">
                        <input 
                          type="text"
                          v-model="uploadDataForm.location" 
                          list="ud-branch-list" 
                          :disabled="!uploadDataRegion"
                          placeholder="-- Ketik / Pilih Branch --"
                          class="w-full text-sm bg-theme-surface border border-theme-border-default rounded-lg px-2 py-2 focus:ring-theme-brand-secondary focus:border-theme-brand-secondary text-theme-text-primary shadow-sm disabled:opacity-50 disabled:cursor-not-allowed"
                        >
                        <datalist id="ud-branch-list">
                          <template v-if="uploadDataRegion">
                            <option v-for="branch in (branchOptions.find(g => g.group === uploadDataRegion)?.options || [])" :key="'ud_' + branch" :value="branch"></option>
                          </template>
                        </datalist>
                      </div>
                    </div>
                  </div>

                  <div class="flex gap-4 mt-3 pointer-events-auto">
                    <button 
                      @click.stop="uploadDataForm.data_file = null" 
                      class="px-5 py-2.5 rounded-xl border border-theme-border-default text-theme-text-primary hover:bg-black/5 dark:hover:bg-white/10 transition-colors font-medium active:scale-[0.98] transition-transform"
                    >
                      Batal
                    </button>
                    <button 
                      @click.stop="uploadData" 
                      class="px-6 py-2.5 rounded-xl bg-theme-brand-secondary hover:bg-theme-brand-secondary/90 text-white shadow-sm transition-all font-semibold active:scale-[0.98] transition-transform"
                    >
                      Upload Data
                    </button>
                  </div>
                </div>

                <!-- Default State (No Files) -->
                <template v-else>
                  <div class="w-20 h-20 bg-green-500/10 rounded-full flex items-center justify-center mb-5 group-hover:scale-110 group-hover:shadow-[0_0_30px_rgba(34,197,94,0.2)] transition-all group-hover:bg-green-500/20">
                    <svg class="anime-svg-draw w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                  </div>
                  <span class="text-xl font-bold text-slate-800 mb-2 group-hover:text-green-600 transition-colors dark:text-slate-900">Pilih Data Excel / CSV</span>
                  <span class="text-sm text-slate-500 bg-slate-100 px-3 py-1 rounded-full border border-slate-200 dark:text-slate-900">Mendukung format XLSX, XLS, CSV, TXT</span>
                </template>
              </label>
            </div>
          </div>

          <!-- ACTIVE SESSION OUTPUTS -->
          <template v-else>
            <!-- Top Action Bar -->
            <div
              class="flex justify-between items-center bg-white dark:bg-primary p-4 rounded-2xl border border-slate-200 dark:border-black shadow-sm mb-6"
            >
              <h2 class="text-xl font-bold text-slate-800 dark:text-slate-900">
                {{ activeSession.filename }}
              </h2>
              <div class="flex gap-3 items-center">
                <label
                  class="cursor-pointer px-4 py-2 bg-theme-surface border border-theme-border-default text-theme-text-primary hover:bg-theme-secondary rounded-lg text-sm font-medium transition flex items-center gap-2 shadow-sm"
                  :class="{
                    'opacity-50 cursor-not-allowed':
                      form.processing,
                  }"
                  title="Tambahkan gambar pricelist ke sesi ini"
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
                  Input Gambar
                  <input
                    type="file"
                    accept="image/*,.zip"
                    multiple
                    @change="
                      (e) => {
                        if (e.target.files.length > 0) {
                          form.images = Array.from(e.target.files);
                          form.locations = Array(form.images.length).fill('');
                          activeUploadMode = 'image';
                          showActiveUploadModal = true;
                        }
                      }
                    "
                    class="hidden"
                    :disabled="form.processing"
                  />
                </label>
                
                <label
                  class="cursor-pointer px-4 py-2 bg-theme-surface border border-theme-border-default text-theme-text-primary hover:bg-theme-secondary rounded-lg text-sm font-medium transition flex items-center gap-2 shadow-sm"
                  :class="{
                    'opacity-50 cursor-not-allowed':
                      form.processing,
                  }"
                  title="Tambahkan data manual excel/csv ke sesi ini"
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
                  Input Excel
                  <input
                    type="file"
                    accept=".csv,.xlsx,.xls,.txt"
                    multiple
                    @change="
                      (e) => {
                        if (e.target.files.length > 0) {
                          form.images = Array.from(e.target.files);
                          activeUploadMode = 'excel';
                          showActiveUploadModal = true;
                        }
                      }
                    "
                    class="hidden"
                    :disabled="form.processing"
                  />
                </label>
                <div class="relative group z-50">
                  <button 
                    v-if="
                      activeSession.packages &&
                      activeSession.packages.length > 0
                    "
                    class="px-4 py-2 bg-theme-surface text-theme-text-primary hover:bg-theme-secondary border border-theme-border-default rounded-lg text-sm font-medium transition flex items-center gap-2 shadow-sm"
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
                    Download
                  </button>
                  <div class="absolute right-0 pt-2 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all">
                    <div class="w-40 bg-theme-surface border border-theme-border-default rounded-lg shadow-xl overflow-hidden flex flex-col">
                      <a :href="route('scanner.export', activeSession.id)" target="_blank" class="px-4 py-2 text-sm text-theme-text-primary hover:bg-theme-secondary transition-colors flex items-center gap-2">
                        <span>📊</span> Excel
                      </a>
                      <a :href="route('scanner.exportCsv', activeSession.id)" target="_blank" class="px-4 py-2 text-sm text-theme-text-primary hover:bg-theme-secondary transition-colors flex items-center gap-2 border-t border-theme-border-subtle">
                        <span>📝</span> CSV
                      </a>
                      <a :href="route('scanner.exportTxt', activeSession.id)" target="_blank" class="px-4 py-2 text-sm text-theme-text-primary hover:bg-theme-secondary transition-colors flex items-center gap-2 border-t border-theme-border-subtle">
                        <span>📄</span> Text
                      </a>
                    </div>
                  </div>
                </div>

                <button
                  @click="isChatOpen = true"
                  class="px-4 py-2 bg-transparent text-theme-text-muted hover:bg-theme-secondary hover:text-theme-text-primary rounded-lg text-sm font-medium transition flex items-center gap-2 active:scale-[0.98] transition-transform"
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
                  'Menyusun insight & benchmarking...',
                ].includes(activeSession.status) || activeSession.status?.includes('Mengekstrak data dari gambar')
              "
              class="bg-white p-5 rounded-2xl border border-slate-200 flex flex-col gap-4 shadow-md mb-6"
            >
              <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                  <div
                    class="w-6 h-6 border-2 border-theme-brand-secondary border-t-transparent rounded-full animate-spin"
                  ></div>
                  <span
                    class="text-base font-semibold text-theme-brand-secondary animate-pulse"
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
                  class="px-3 py-1.5 bg-theme-semantic-danger/10 text-theme-semantic-danger hover:bg-theme-semantic-danger/20 border border-theme-semantic-danger/20 rounded-lg transition text-sm font-medium flex items-center gap-1.5 active:scale-[0.98] transition-transform"
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

              <!-- Progress Bar -->
              <div class="flex items-center gap-3 mt-1">
                <div class="w-full bg-theme-border-subtle rounded-full h-2.5 overflow-hidden border border-theme-border-default">
                  <div class="anime-progress-bar bg-theme-brand-secondary h-2.5 rounded-full relative overflow-hidden shadow-sm"
                     style="width: 0%">
                     <div class="absolute inset-0 bg-white/20 w-full h-full animate-[pulse_2s_infinite]"></div>
                  </div>
                </div>
                <span class="text-sm font-bold text-theme-brand-secondary w-10 text-right">{{ extractionProgress }}%</span>
              </div>
            </div>

            <!-- Error State Processing -->
            <div
              v-if="activeSession.status === 'failed'"
              class="bg-red-50/90 border border-red-200 rounded-2xl p-6 flex items-start gap-4 mb-6 shadow-sm"
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
                <h4 class="font-bold text-red-700 text-lg mb-2">
                  Gagal Memproses Data
                </h4>
                <p class="text-red-600 mb-4">
                  {{ activeSession.error_message }}
                </p>
                <button
                  @click="retryScan(activeSession.id)"
                  class="px-4 py-2 bg-red-600 text-slate-800 hover:bg-red-700 rounded-lg transition flex items-center gap-2 font-semibold shadow-sm active:scale-[0.98] transition-transform"
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
              class="bg-white border border-theme-border-default rounded-2xl overflow-hidden shadow-md mb-6 relative"
            >
              <!-- Background accent -->
              <div
                class="absolute top-0 left-0 w-1 h-full bg-secondary"
              ></div>

              <div class="px-6 py-4 border-b border-slate-200 bg-white flex justify-between items-center">
                <h3 class="font-bold text-slate-800 flex items-center gap-2">
                  <svg class="w-5 h-5 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                  Summarize Insight (Competitor Benchmark)
                </h3>
              </div>

              <div class="p-6">
                <div
                  class="grid grid-cols-1 md:grid-cols-2 gap-8"
                >
                  <div>
                    <h4
                      class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4 border-b border-slate-200 pb-2"
                    >
                      The best offer based on validity:
                    </h4>
                    <ul class="space-y-3 text-sm">
                      <li
                        v-for="insight in bestOffersByValidity(
                          insightFilteredPackages,
                        )"
                        :key="insight.label"
                        class="flex items-center gap-3 text-slate-800"
                      >
                        <div
                          class="w-1.5 h-1.5 rounded-full"
                          :style="{ backgroundColor: getProviderColor(insight.provider), boxShadow: `0 0 8px ${getProviderColor(insight.provider)}` }"
                        ></div>
                        <span
                          class="w-20 font-medium text-slate-600"
                          >{{ insight.label }}</span
                        >
                        <span class="text-slate-400"
                          >:</span
                        >
                        <span
                          class="font-bold text-slate-900 tracking-wide"
                          >{{ insight.gb }}GB
                          {{ insight.price }}K</span
                        >
                        <span
                          class="px-2 py-0.5 rounded text-xs font-bold border shadow-xs"
                          :style="{ 
                            color: '#ffffff',
                            backgroundColor: getProviderColor(insight.provider),
                            borderColor: getProviderColor(insight.provider)
                          }"
                          >({{
                            insight.provider
                          }})</span
                        >
                      </li>
                      <li
                        v-if="
                          bestOffersByValidity(
                            insightFilteredPackages,
                          ).length === 0
                        "
                        class="text-slate-400 italic text-xs"
                      >
                        Memproses data...
                      </li>
                    </ul>
                  </div>

                  <div>
                    <h4
                      class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4 border-b border-slate-200 pb-2"
                    >
                      The best offer monthly pack:
                    </h4>
                    <ul class="space-y-3 text-sm">
                      <li
                        v-for="insight in bestOffersMonthly(
                          insightFilteredPackages,
                        )"
                        :key="insight.label"
                        class="flex items-center gap-3 text-slate-800"
                      >
                        <div
                          class="w-1.5 h-1.5 rounded-full"
                          :style="{ backgroundColor: getProviderColor(insight.provider), boxShadow: `0 0 8px ${getProviderColor(insight.provider)}` }"
                        ></div>
                        <span
                          class="w-24 font-medium text-slate-600"
                          >{{ insight.label }}</span
                        >
                        <span class="text-slate-400"
                          >:</span
                        >
                        <span
                          class="font-bold text-slate-900 tracking-wide"
                          >{{ insight.gb }}GB
                          {{ insight.price }}K</span
                        >
                        <span
                          class="px-2 py-0.5 rounded text-xs font-bold border shadow-xs"
                          :style="{ 
                            color: '#ffffff',
                            backgroundColor: getProviderColor(insight.provider),
                            borderColor: getProviderColor(insight.provider)
                          }"
                          >({{
                            insight.provider
                          }})</span
                        >
                      </li>
                      <li
                        v-if="
                          bestOffersMonthly(
                            insightFilteredPackages,
                          ).length === 0
                        "
                        class="text-slate-400 italic text-xs"
                      >
                        Memproses data...
                      </li>
                    </ul>
                  </div>
                </div>
                
                <!-- Market Summarize Tabbed View -->
                <div class="mt-8 pt-8 border-t border-slate-200">
                  <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6 gap-4">
                    <h4 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Market Summarize</h4>
                    <div class="flex flex-col sm:flex-row items-end sm:items-center gap-3">
                      <div class="flex items-center gap-3">
                        <div v-if="insightTimeFilter === 'Rentang Waktu'" class="flex items-center gap-2">
                          <input type="date" v-model="insightStartDate" class="bg-white text-sm text-slate-800 border border-slate-300 rounded-md px-3 py-1.5 focus:outline-none focus:border-indigo-500 shadow-sm" />
                          <span class="text-slate-400">-</span>
                          <input type="date" v-model="insightEndDate" class="bg-white text-sm text-slate-800 border border-slate-300 rounded-md px-3 py-1.5 focus:outline-none focus:border-indigo-500 shadow-sm" />
                        </div>
                        <select v-model="insightTimeFilter" class="bg-white text-sm text-slate-800 border border-slate-300 rounded-lg px-3 py-1.5 focus:outline-none focus:border-indigo-500 shadow-sm">
                          <option>Semua Waktu</option>
                          <option>Hari Ini</option>
                          <option>Minggu Ini</option>
                          <option>Bulan Ini</option>
                          <option>Tahun Ini</option>
                          <option value="Rentang Waktu">Pilih Tanggal / Rentang</option>
                        </select>
                        <select v-model="insightRegionFilter" @change="insightBranchFilter = 'Semua Branch'" class="bg-white text-sm text-slate-800 border border-slate-300 rounded-lg px-3 py-1.5 focus:outline-none focus:border-indigo-500 shadow-sm">
                          <option>Semua Region</option>
                          <option v-for="group in branchOptions" :key="group.group" :value="group.group">{{ group.group }}</option>
                        </select>
                        <select v-model="insightBranchFilter" :disabled="insightRegionFilter === 'Semua Region'" class="bg-white text-sm text-slate-800 border border-slate-300 rounded-lg px-3 py-1.5 focus:outline-none focus:border-indigo-500 shadow-sm disabled:opacity-50">
                          <option>Semua Branch</option>
                          <template v-if="insightRegionFilter !== 'Semua Region'">
                            <option v-for="opt in branchOptions.find(g => g.group === insightRegionFilter)?.options || []" :key="opt" :value="opt">{{ opt }}</option>
                          </template>
                        </select>
                      </div>
                      <select v-model="marketSummaryFilter" class="bg-white border border-slate-300 text-slate-800 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block px-3 py-1.5 outline-none shadow-sm">
                        <option value="all">Keseluruhan</option>
                        <option value="harian">Paket Harian (Sachet)</option>
                        <option value="mingguan">Paket Mingguan</option>
                        <option value="bulanan_sachet">Paket Bulanan (Sachet)</option>
                        <option value="bulanan_premium">Paket Bulanan (Premium / Jumbo)</option>
                      </select>
                    </div>
                  </div>
                  
                  <div class="flex flex-col gap-6">
                    <!-- Metric Selection Tabs -->
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                      <!-- Average Yield Tab -->
                      <button @click="activeSummaryTab = 'yield'" 
                        class="flex flex-col items-start p-4 rounded-xl border transition-all h-full text-left w-full active:scale-[0.98] transition-transform"
                        :class="activeSummaryTab === 'yield' ? 'bg-white border-blue-500 shadow-md ring-1 ring-blue-500' : 'bg-white border-enterprise-border hover:bg-slate-50'">
                        <div class="text-xs font-semibold tracking-wide uppercase mb-2" :class="activeSummaryTab === 'yield' ? 'text-blue-600' : 'text-enterprise-muted dark:text-slate-600'">Average Yield</div>
                        <div class="font-bold flex items-baseline gap-2 text-enterprise-text dark:text-slate-900 text-xl" v-if="marketAverages.yield">
                           {{ marketAverages.yield.value }}
                           <span class="text-xs font-medium text-enterprise-muted dark:text-slate-600 lowercase">/GB ({{ marketAverages.yield.provider }})</span>
                        </div>
                      </button>
                      
                      <!-- Average Price Tab -->
                      <button @click="activeSummaryTab = 'price'" 
                        class="flex flex-col items-start p-4 rounded-xl border transition-all h-full text-left w-full active:scale-[0.98] transition-transform"
                        :class="activeSummaryTab === 'price' ? 'bg-white border-blue-500 shadow-md ring-1 ring-blue-500' : 'bg-white border-enterprise-border hover:bg-slate-50'">
                        <div class="text-xs font-semibold tracking-wide uppercase mb-2" :class="activeSummaryTab === 'price' ? 'text-blue-600' : 'text-enterprise-muted dark:text-slate-600'">Average Price</div>
                        <div class="font-bold flex items-baseline gap-2 text-enterprise-text dark:text-slate-900 text-xl" v-if="marketAverages.price">
                           {{ marketAverages.price.value }}
                           <span class="text-xs font-medium text-enterprise-muted dark:text-slate-600">({{ marketAverages.price.provider }})</span>
                        </div>
                      </button>

                      <!-- Average Data Quota Tab -->
                      <button @click="activeSummaryTab = 'quota'" 
                        class="flex flex-col items-start p-4 rounded-xl border transition-all h-full text-left w-full active:scale-[0.98] transition-transform"
                        :class="activeSummaryTab === 'quota' ? 'bg-white border-blue-500 shadow-md ring-1 ring-blue-500' : 'bg-white border-enterprise-border hover:bg-slate-50'">
                        <div class="text-xs font-semibold tracking-wide uppercase mb-2" :class="activeSummaryTab === 'quota' ? 'text-blue-600' : 'text-enterprise-muted dark:text-slate-600'">Average Quota</div>
                        <div class="font-bold flex items-baseline gap-2 text-enterprise-text dark:text-slate-900 text-xl" v-if="marketAverages.quota">
                           {{ marketAverages.quota.value }}
                           <span class="text-xs font-medium text-enterprise-muted dark:text-slate-600">({{ marketAverages.quota.provider }})</span>
                        </div>
                      </button>

                      <!-- Average Validity Tab -->
                      <button @click="activeSummaryTab = 'validity'" 
                        class="flex flex-col items-start p-4 rounded-xl border transition-all h-full text-left w-full active:scale-[0.98] transition-transform"
                        :class="activeSummaryTab === 'validity' ? 'bg-white border-blue-500 shadow-md ring-1 ring-blue-500' : 'bg-white border-enterprise-border hover:bg-slate-50'">
                        <div class="text-xs font-semibold tracking-wide uppercase mb-2" :class="activeSummaryTab === 'validity' ? 'text-blue-600' : 'text-enterprise-muted dark:text-slate-600'">Average Validity</div>
                        <div class="font-bold flex items-baseline gap-2 text-enterprise-text dark:text-slate-900 text-xl" v-if="marketAverages.validity">
                           {{ marketAverages.validity.value }}
                           <span class="text-xs font-medium text-enterprise-muted dark:text-slate-600">({{ marketAverages.validity.provider }})</span>
                        </div>
                      </button>
                    </div>

                    <!-- Ranking Table -->
                    <div class="bg-transparent mt-4 relative">
                      <h4 class="text-sm font-semibold text-slate-800 dark:text-slate-900 uppercase tracking-wider mb-6 pb-2 border-b border-slate-200 flex items-center gap-2">
                        Provider Ranking by 
                        <span v-if="activeSummaryTab === 'yield'" class="text-secondary font-bold">Average Yield</span>
                        <span v-else-if="activeSummaryTab === 'price'" class="text-secondary font-bold">Average Price</span>
                        <span v-else-if="activeSummaryTab === 'quota'" class="text-secondary font-bold">Average Data Quota</span>
                        <span v-else-if="activeSummaryTab === 'validity'" class="text-secondary font-bold">Average Validity</span>
                      </h4>
                      
                      <div class="relative pb-8 pt-2" v-if="form.processing || trendLoading">
                        <div class="animate-pulse space-y-5">
                          <div v-for="i in 5" :key="i" class="flex items-center w-full">
                            <div class="w-8 h-4 bg-slate-200 dark:bg-slate-700 rounded mr-2 flex-shrink-0"></div>
                            <div class="w-20 h-4 bg-slate-200 dark:bg-slate-700 rounded mr-4 flex-shrink-0"></div>
                            <div class="flex-1">
                              <div class="h-4 bg-slate-100 dark:bg-slate-800 rounded-r-lg" :style="'width: ' + (Math.random() * 50 + 30) + '%'"></div>
                            </div>
                            <div class="w-16 h-4 bg-slate-200 dark:bg-slate-700 rounded ml-4 flex-shrink-0"></div>
                          </div>
                        </div>
                      </div>
                      <div class="relative pb-8 pt-2" v-else-if="marketAverages[activeSummaryTab]">
                        <div class="space-y-4 relative w-full pr-4">
                          <!-- Continuous Y-Axis Line -->
                          <div class="absolute top-0 -bottom-2 left-[8rem] w-[2px] bg-slate-300 z-0"></div>
                          <div v-for="(item, index) in marketAverages[activeSummaryTab].list" :key="item.provider" class="flex items-center group w-full">
                            <div class="w-8 font-bold text-sm text-center flex-shrink-0" :class="index === 0 ? 'text-yellow-500' : index === 1 ? 'text-slate-500' : index === 2 ? 'text-amber-600' : 'text-slate-400'">
                              #{{ index + 1 }}
                            </div>
                            <span class="w-24 pr-4 font-bold flex-shrink-0 text-sm text-right text-slate-800 dark:text-slate-900 relative cursor-help">
                              {{ item.provider }}
                              <!-- Tooltip Popup -->
                              <div class="absolute left-0 bottom-full mb-2 w-64 bg-white border border-slate-200 text-slate-900 rounded-lg p-3 shadow-2xl opacity-0 group-hover:opacity-100 pointer-events-none transition-all duration-300 z-50 transform translate-y-2 group-hover:translate-y-0 text-left">
                                <div class="text-[11px] text-slate-600 font-normal space-y-1.5">
                                  <div class="border-b border-slate-200 pb-1.5 mb-1.5 font-bold text-slate-900 uppercase text-xs">{{ item.provider }} Details</div>
                                  <div class="flex justify-between"><span>Jumlah Paket:</span> <span class="text-slate-900 font-bold">{{ item.count }} paket</span></div>
                                  
                                  <div v-if="activeSummaryTab === 'quota'" class="space-y-1.5">
                                    <div class="flex justify-between"><span>Total Quota (Semua Paket):</span> <span class="text-slate-900 font-bold">{{ formatNumber(item.sumGb, 1) }} GB</span></div>
                                    <div class="mt-2 pt-1 border-t border-slate-200">
                                      <div class="text-green-600 font-medium">Tertinggi: <span class="text-slate-900 ">{{ formatNumber(item.details.quota.max.gb, 1) }} GB</span></div>
                                      <div class="text-slate-400 line-clamp-1">{{ item.details.quota.max.package_name }} <span class="text-slate-400">({{ item.details.quota.max.gb }}GB, {{ item.details.quota.max.days }} Hari)</span></div>
                                    </div>
                                    <div class="mt-1">
                                      <div class="text-red-500 font-medium">Terendah: <span class="text-slate-900 ">{{ formatNumber(item.details.quota.min.gb, 1) }} GB</span></div>
                                      <div class="text-slate-400 line-clamp-1">{{ item.details.quota.min.package_name }} <span class="text-slate-400">({{ item.details.quota.min.gb }}GB, {{ item.details.quota.min.days }} Hari)</span></div>
                                    </div>
                                  </div>
                                  
                                  <div v-else-if="activeSummaryTab === 'price'" class="space-y-1.5">
                                    <div class="flex justify-between"><span>Total Harga Keseluruhan:</span> <span class="text-slate-900 font-bold">Rp{{ formatNumber(item.sumPrice, 0) }}</span></div>
                                    <div class="mt-2 pt-1 border-t border-slate-200">
                                      <div class="text-red-500 font-medium">Termahal: <span class="text-slate-900 ">Rp{{ formatNumber(item.details.price.max.price, 0) }}</span></div>
                                      <div class="text-slate-400 line-clamp-1">{{ item.details.price.max.package_name }} <span class="text-slate-400">({{ item.details.price.max.gb }}GB, {{ item.details.price.max.days }} Hari)</span></div>
                                    </div>
                                    <div class="mt-1">
                                      <div class="text-green-600 font-medium">Termurah: <span class="text-slate-900 ">Rp{{ formatNumber(item.details.price.min.price, 0) }}</span></div>
                                      <div class="text-slate-500 line-clamp-1">{{ item.details.price.min.package_name }} <span class="text-slate-500">({{ item.details.price.min.gb }}GB, {{ item.details.price.min.days }} Hari)</span></div>
                                    </div>
                                  </div>
                                  
                                  <div v-else-if="activeSummaryTab === 'yield'" class="space-y-1.5">
                                    <div class="mt-2 pt-1 border-t border-slate-200">
                                      <div class="text-green-600 font-medium">Yield Terbaik (Terendah): <span class="text-slate-900 ">Rp{{ formatNumber(item.details.yield.min.yield_val, 0) }}/GB</span></div>
                                      <div class="text-slate-500 line-clamp-1">{{ item.details.yield.min.package_name }} <span class="text-slate-500">({{ item.details.yield.min.gb }}GB, {{ item.details.yield.min.days }} Hari)</span></div>
                                    </div>
                                    <div class="mt-1">
                                      <div class="text-red-500 font-medium">Yield Terburuk: <span class="text-slate-900 ">Rp{{ formatNumber(item.details.yield.max.yield_val, 0) }}/GB</span></div>
                                      <div class="text-slate-500 line-clamp-1">{{ item.details.yield.max.package_name }} <span class="text-slate-500">({{ item.details.yield.max.gb }}GB, {{ item.details.yield.max.days }} Hari)</span></div>
                                    </div>
                                  </div>
                                  
                                  <div v-else-if="activeSummaryTab === 'validity'" class="space-y-1.5">
                                    <div class="mt-2 pt-1 border-t border-slate-200">
                                      <div class="text-green-600 font-medium">Terlama: <span class="text-slate-900 ">{{ item.details.validity.max.days }} Hari</span></div>
                                      <div class="text-slate-500 line-clamp-1">{{ item.details.validity.max.package_name }} <span class="text-slate-500">({{ item.details.validity.max.gb }}GB)</span></div>
                                    </div>
                                    <div class="mt-1">
                                      <div class="text-red-500 font-medium">Tersingkat: <span class="text-slate-900 ">{{ item.details.validity.min.days }} Hari</span></div>
                                      <div class="text-slate-500 line-clamp-1">{{ item.details.validity.min.package_name }} <span class="text-slate-500">({{ item.details.validity.min.gb }}GB)</span></div>
                                    </div>
                                  </div>
                                  
                                </div>
                              </div>
                            </span>
                            
                            <!-- Flat Bar Chart -->
                            <div class="flex-1 flex items-center h-4 relative z-10 group-hover:opacity-90">
                              <div class="h-full transition-all duration-1000 rounded-r shadow-sm" :style="{ width: Math.max(item.percent, 1) + '%', backgroundColor: getProviderColor(item.provider) }"></div>
                              <span class="ml-3 font-bold text-slate-800 text-sm whitespace-nowrap">{{ item.value }}</span>
                            </div>
                          </div>
                        </div>
                        
                        <!-- X-Axis Scale Line -->
                        <div class="flex items-center group w-full mt-2 pr-4">
                          <div class="w-8 flex-shrink-0"></div>
                          <div class="w-24 pr-4 flex-shrink-0"></div>
                          <div class="flex-1 relative h-6 border-t border-slate-300">
                            <div v-for="tick in marketAverages[activeSummaryTab].axis.ticks" :key="tick" 
                               class="absolute top-0 h-2 border-l border-slate-300"
                               :style="{ left: (tick / marketAverages[activeSummaryTab].axis.maxTick) * 100 + '%' }">
                              <span class="absolute top-full mt-1 -translate-x-1/2 text-[10px] text-slate-500 font-medium whitespace-nowrap">
                                {{ formatNumber(tick, 0) }}
                              </span>
                            </div>
                          </div>
                        </div>
                      </div>
                      <div v-else class="flex flex-col items-center justify-center py-10 px-4 text-center">
                        <div class="bg-slate-50 dark:bg-slate-800 rounded-full p-4 mb-4">
                          <svg class="w-10 h-10 text-slate-300 dark:text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path></svg>
                        </div>
                        <h3 class="text-slate-700 dark:text-slate-300 font-semibold mb-1 text-sm">Tidak Ada Data</h3>
                        <p class="text-slate-500 dark:text-slate-500 text-xs max-w-xs">Belum ada data untuk kategori ini.</p>
                      </div>
                    </div>
                  </div>
                </div>
                
                <!-- Competitiveness Summarize Section -->
                <div class="anim-on-scroll opacity-0 mt-8 pt-8 border-t border-slate-200" v-if="activeSession.packages && activeSession.packages.length > 0">
                <div class="mb-6 border-b border-slate-200 pb-2">
                  <h4 class="text-sm font-bold text-slate-800 uppercase tracking-widest">Competitiveness Summarize</h4>
                </div>
              
              <div class="grid grid-cols-1 lg:grid-cols-10 gap-6">
                <!-- Competitive Heatmap (Far left, col-span-4) -->
                <div class="lg:col-span-4 bg-transparent p-2 flex flex-col relative overflow-hidden">
                  <h5 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-1 text-center">Competitive Heatmap</h5>
                  <p class="text-[10px] text-slate-400 text-center mb-4 italic">(By Minimum Yield Rp/GB & Overall Score)</p>
                  <div class="flex-grow flex flex-col justify-center">
                    <table class="w-full text-left border-collapse">
                      <thead>
                        <tr>
                          <th class="p-1 border-b border-slate-200 text-slate-500 font-bold text-[10px]">Provider</th>
                          <th v-for="col in competitiveHeatmapData.columns" :key="col.key" class="p-1 border-b border-slate-200 text-center text-slate-500 font-bold text-[10px]">{{ col.label }}</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="row in competitiveHeatmapData.rows" :key="row.provider" class="border-b border-slate-100 hover:bg-slate-50/80 transition-colors">
                          <td class="p-1 text-xs font-extrabold" :style="{color: getProviderColor(row.provider)}">{{ row.provider }}</td>
                          <td v-for="col in competitiveHeatmapData.columns" :key="col.key" class="p-1 text-center text-sm">
                            <div v-if="row.cells[col.key] === 'green'" class="mx-auto w-3 h-3 rounded-full bg-emerald-500 shadow-xs" title="Strong"></div>
                            <div v-else-if="row.cells[col.key] === 'yellow'" class="mx-auto w-3 h-3 rounded-full bg-amber-500 shadow-xs" title="Competitive"></div>
                            <div v-else-if="row.cells[col.key] === 'red'" class="mx-auto w-3 h-3 rounded-full bg-rose-600 shadow-xs" title="Weak"></div>
                            <div v-else class="mx-auto w-2 h-2 rounded-full bg-slate-300" title="No Product"></div>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                    
                    <!-- Legend -->
                    <div class="mt-4 flex flex-wrap justify-start gap-x-3 gap-y-2 text-[10px] text-slate-600 font-medium">
                      <div class="flex items-center gap-2"><div class="w-3 h-3 rounded-full bg-emerald-500 shadow-xs"></div> <span>Strong</span></div>
                      <div class="flex items-center gap-2"><div class="w-3 h-3 rounded-full bg-amber-500 shadow-xs"></div> <span>Competitive</span></div>
                      <div class="flex items-center gap-2"><div class="w-3 h-3 rounded-full bg-rose-600 shadow-xs"></div> <span>Weak</span></div>
                      <div class="flex items-center gap-2"><div class="w-2 h-2 rounded-full bg-slate-300"></div> <span>No Product</span></div>
                    </div>
                  </div>
                </div>
                
                <!-- Overall Competitiveness -->
                <div class="lg:col-span-3 bg-transparent p-2 flex flex-col relative overflow-hidden">
                  <h5 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-1 text-center">Overall Competitiveness</h5>
                  <p class="text-[10px] text-slate-400 text-center mb-4 italic">(By Average Package Efficiency)</p>
                  <div class="flex-grow min-h-[200px] relative">
                    <Bar v-if="overallCompetitivenessChartData.labels.length" :data="overallCompetitivenessChartData" :options="insightChartOptions" />
                    <div v-else class="flex h-full items-center justify-center text-slate-400 text-sm">Tidak ada data</div>
                  </div>
                </div>
                
                <!-- Yield Distribution -->
                <div class="lg:col-span-3 bg-transparent p-2 flex flex-col relative overflow-hidden">
                  <h5 class="text-xs font-bold text-slate-800 uppercase tracking-wider mb-1 text-center">Yield Distribution</h5>
                  <p class="text-[10px] text-slate-400 text-center mb-4 italic">(By Maximum Yield Rp/GB)</p>
                  <div class="flex-grow min-h-[200px] relative">
                    <Bar v-if="yieldDistributionChartData.labels.length" :data="yieldDistributionChartData" :options="insightChartOptions" />
                    <div v-else class="flex h-full items-center justify-center text-slate-400 text-sm">Tidak ada data</div>
                  </div>
                </div>
                
              </div>
            </div>

            <!-- Competitive Yield Landscape Section -->
            <div class="mt-10 pt-8 border-t border-slate-200">
            
            <!-- Header & Title -->
            <div class="mb-6 border-b border-slate-200 pb-4">
              <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" viewBox="0 0 20 20" fill="currentColor">
                  <path d="M2 10a8 8 0 018-8v8h8a8 8 0 11-16 0z" />
                  <path d="M12 2.252A8.014 8.014 0 0117.748 8H12V2.252z" />
                </svg>
                Competitive Yield Landscape — Circle Java & Market Overview
              </h3>
              <p class="text-xs text-slate-500 mt-1 font-medium">
                Yield = EUP / Total GB (Rp per GB) · dots = individual SKUs (jittered) · dashed line = median across all operators per bucket · click legend to toggle operator
              </p>
            </div>

            <!-- Interactive Legend & Median Indicator -->
            <div class="flex flex-wrap items-center justify-between gap-4 mb-8 px-4 py-3 bg-slate-50 border border-slate-200/80 rounded-xl shadow-xs">
              <div class="flex flex-wrap items-center gap-2.5">
                <span class="text-xs font-bold text-slate-800 mr-2">Provider:</span>
                
                <!-- Grouped Providers -->
                <div v-for="group in providerGroups" :key="group.parent" class="flex items-stretch rounded-lg overflow-hidden border border-slate-200/60 shadow-xs" :style="{ backgroundColor: group.bgColor }">
                  <button @click="toggleYieldProviderGroup(group.parent)" class="px-3 py-1.5 text-xs font-extrabold flex items-center justify-center transition-opacity hover:opacity-90" :style="{ backgroundColor: group.parentColor, color: group.parentTextColor || '#fff' }">
                    {{ group.parent }}
                  </button>
                  <button
                    v-for="child in group.children"
                    :key="child.value"
                    @click="toggleYieldProvider(child.value)"
                    class="px-3 py-1.5 text-xs font-extrabold transition-all duration-200 border-l border-slate-200/30 flex items-center gap-2"
                    :class="isYieldProviderActive(child.value) ? 'opacity-100' : 'opacity-40 hover:opacity-60'"
                    :style="{ color: '#1e293b' }"
                  >
                    <span>{{ child.name }}</span>
                  </button>
                </div>
                
                <!-- Standalone Providers -->
                <button
                  v-for="prov in yieldLandscapeProviders.filter(p => !providerGroups.some(g => g.children.some(c => c.value === p)))"
                  :key="prov"
                  @click="toggleYieldProvider(prov)"
                  class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-extrabold transition-all duration-200 border active:scale-[0.98] transition-transform"
                  :class="isYieldProviderActive(prov)
                    ? 'bg-white text-slate-800 border-slate-300 shadow-xs ring-1 ring-slate-200/50'
                    : 'bg-slate-200/60 text-slate-400 border-transparent opacity-60 hover:opacity-80'"
                >
                  <span class="w-2.5 h-2.5 rounded-full shadow-xs" :style="{ backgroundColor: isYieldProviderActive(prov) ? getProviderColor(prov) : '#94a3b8' }"></span>
                  <span>{{ prov }}</span>
                </button>
              </div>
              <div class="flex items-center gap-2.5 text-xs font-extrabold text-slate-800 bg-white px-3 py-1.5 rounded-lg border border-slate-200 shadow-xs">
                <span class="inline-block w-6 border-t-2 border-dashed border-slate-500 relative">
                  <span class="absolute left-1/2 -top-[4px] -translate-x-1/2 w-2 h-2 bg-slate-700 transform rotate-45 border border-secondary"></span>
                </span>
                <span>Median per bucket</span>
              </div>
            </div>

            <!-- Chart 1: Monthly (Paket Bulanan) -->
            <div class="mb-12">
              <div class="mb-4">
                <div class="flex items-center gap-2.5">
                  <span class="bg-blue-100 text-blue-700 font-extrabold text-[11px] px-2.5 py-0.5 rounded-md tracking-wider border border-blue-200 uppercase shadow-xs">Monthly</span>
                  <h4 class="text-base font-extrabold text-slate-800">Paket Bulanan — Yield vs Slab EUP (25K bins)</h4>
                </div>
                <p class="text-xs text-slate-500 mt-1 font-medium">Grouped by end-user price. Lower yield = better Rp/GB (more aggressive pricing).</p>
              </div>
              <div class="h-[420px] w-full border border-slate-200 rounded-xl p-4 bg-white shadow-xs">
                <Scatter
                  v-if="monthlyYieldChartData.datasets.length > 0"
                  :data="monthlyYieldChartData"
                  :options="getYieldChartOptions(['0–25K', '25–50K', '50–75K', '75–100K', '100–125K', '125–150K', '150–200K', '200K+'])"
                />
                <div v-else class="flex flex-col h-full items-center justify-center py-10 px-4 text-center">
                  <div class="bg-indigo-50 dark:bg-indigo-900/20 rounded-full p-4 mb-4">
                    <svg class="w-10 h-10 text-indigo-300 dark:text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                  </div>
                  <h3 class="text-slate-700 dark:text-slate-300 font-semibold mb-1 text-sm">Kosong</h3>
                  <p class="text-slate-500 dark:text-slate-500 text-xs max-w-xs">Belum ada data paket bulanan untuk ditampilkan.</p>
                </div>
              </div>
            </div>

            <!-- Chart 2: Sachet / Daily (Paket Harian) -->
            <div>
              <div class="mb-4">
                <div class="flex items-center gap-2.5">
                  <span class="bg-amber-100 text-amber-800 font-extrabold text-[11px] px-2.5 py-0.5 rounded-md tracking-wider border border-amber-200 uppercase shadow-xs">Sachet</span>
                  <h4 class="text-base font-extrabold text-slate-800">Paket Harian — Yield vs Validity (hari)</h4>
                </div>
                <p class="text-xs text-slate-500 mt-1 font-medium">Grouped by validity days (1d–19d). Short validity usually carries higher Rp/GB.</p>
              </div>
              <div class="h-[420px] w-full border border-slate-200 rounded-xl p-4 bg-white shadow-xs">
                <Scatter
                  v-if="sachetYieldChartData.datasets.length > 0"
                  :data="sachetYieldChartData"
                  :options="getYieldChartOptions(sachetYieldLabels)"
                />
                <div v-else class="flex flex-col h-full items-center justify-center py-10 px-4 text-center">
                  <div class="bg-orange-50 dark:bg-orange-900/20 rounded-full p-4 mb-4">
                    <svg class="w-10 h-10 text-orange-300 dark:text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                  </div>
                  <h3 class="text-slate-700 dark:text-slate-300 font-semibold mb-1 text-sm">Kosong</h3>
                  <p class="text-slate-500 dark:text-slate-500 text-xs max-w-xs">Belum ada data paket harian/sachet untuk ditampilkan.</p>
                </div>
              </div>
            </div>
          </div>
          </div>
          </div>

          <!-- AI Strategic Insight Section -->
          <div class="mt-8 bg-white border border-enterprise-border rounded-xl shadow-sm mb-8 p-6" v-if="activeSession.packages && activeSession.packages.length > 0">
            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
              <h4 class="text-sm font-semibold text-enterprise-text dark:text-slate-900 uppercase tracking-wide flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                 <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                AI Strategic Insight
              </h4>
              <button @click="generateAiInsight" :disabled="aiInsightLoading" class="mt-4 sm:mt-0 bg-theme-brand-secondary hover:bg-theme-brand-secondary/90 text-white font-medium text-sm py-2 px-4 rounded-lg transition-colors disabled:opacity-50 flex items-center gap-2 shadow-sm active:scale-[0.98] transition-transform">
                <svg v-if="aiInsightLoading" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>{{ aiInsightLoading ? 'Analyzing Market...' : 'Generate AI Strategy' }}</span>
              </button>
            </div>
            <div class="bg-slate-50 rounded-lg p-6 border border-enterprise-border min-h-[150px]">
              <div v-if="aiInsightLoading" class="animate-pulse space-y-4 py-2">
                <div class="h-4 bg-slate-200 dark:bg-slate-700 rounded w-1/3 mb-4"></div>
                <div class="space-y-3">
                  <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-full"></div>
                  <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-11/12"></div>
                  <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-4/5"></div>
                  <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-full"></div>
                  <div class="h-3 bg-slate-200 dark:bg-slate-700 rounded w-3/4"></div>
                </div>
                <div class="flex gap-3 pt-3 mt-2 border-t border-slate-100 dark:border-slate-700">
                  <div class="h-6 w-20 bg-indigo-100 dark:bg-indigo-900 rounded-full"></div>
                  <div class="h-6 w-24 bg-indigo-100 dark:bg-indigo-900 rounded-full"></div>
                </div>
              </div>
              <div v-else-if="aiInsightData" class="prose prose-sm max-w-none prose-slate text-enterprise-text dark:text-slate-900" v-html="parseMarkdown(aiInsightData)"></div>
              <div v-else class="flex items-center justify-center h-full text-enterprise-muted dark:text-slate-900 py-10 text-sm">
                Klik tombol di atas untuk mendapatkan insight pasar dan strategi dari AI.
              </div>
            </div>
          </div>

          <!-- Market Trend Section -->
            <div class="anim-on-scroll opacity-0 bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-md mb-8 p-6">
              
              <!-- Header & Title -->
              <div class="mb-6 border-b border-slate-200 pb-4">
                <h3 class="text-lg font-bold text-slate-800 flex items-center gap-2">
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-theme-brand-secondary" viewBox="0 0 20 20" fill="currentColor">
                    <path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z" />
                  </svg>
                  Market Trend & Pricing Evolution
                </h3>
                <p class="text-xs text-slate-500 mt-1 font-medium">
                  Pantau pergerakan rata-rata harga, yield (Rp/GB), dan jumlah paket yang ditawarkan dari waktu ke waktu.
                </p>
              </div>

              <!-- Interactive Filter Container -->
              <div class="flex flex-wrap items-center justify-between gap-4 mb-8 px-4 py-3 bg-slate-50 border border-slate-200/80 rounded-xl shadow-xs">
                <div class="flex flex-wrap items-center gap-2.5">
                  <span class="text-xs font-bold text-slate-800 mr-2">Filter Data:</span>
                  <select 
                    v-model="trendMetric" 
                    class="bg-white text-slate-800 border border-slate-300 rounded-lg text-xs font-bold py-1.5 px-3 focus:ring-blue-500 focus:border-blue-500 shadow-sm transition active:scale-[0.98]"
                  >
                    <option value="avg_price">Rata-rata Harga</option>
                    <option value="avg_yield">Rata-rata Yield</option>
                    <option value="count">Jumlah Paket</option>
                  </select>

                  <select v-model="trendCircle" class="bg-white text-slate-800 border border-slate-300 rounded-lg text-xs font-bold py-1.5 px-3 focus:ring-blue-500 focus:border-blue-500 shadow-sm transition active:scale-[0.98]">
                    <option value="">Semua Circle</option>
                    <option value="Java Bali Nusra">Java Bali Nusra</option>
                  </select>
                  
                  <select v-model="trendRegion" class="bg-white text-slate-800 border border-slate-300 rounded-lg text-xs font-bold py-1.5 px-3 focus:ring-blue-500 focus:border-blue-500 shadow-sm transition active:scale-[0.98]">
                    <option value="">Semua Region</option>
                    <option value="Central Java">Central Java</option>
                    <option value="East Java">East Java</option>
                    <option value="Bali Nusra">Bali Nusra</option>
                  </select>
                  
                  <select v-model="trendBranch" class="bg-white text-slate-800 border border-slate-300 rounded-lg text-xs font-bold py-1.5 px-3 focus:ring-blue-500 focus:border-blue-500 shadow-sm max-w-[140px] truncate transition active:scale-[0.98]">
                    <option value="">Semua Branch</option>
                    <optgroup v-for="group in branchOptions" :key="group.group" :label="group.group">
                      <option v-for="opt in group.options" :key="opt" :value="opt">{{ opt }}</option>
                    </optgroup>
                  </select>
                  
                  <div class="relative min-w-[160px]">
                    <button @click="isTrendFileFilterOpen = !isTrendFileFilterOpen" class="w-full text-left bg-white text-xs font-bold text-slate-800 border border-slate-300 rounded-lg px-3 py-1.5 focus:outline-none focus:ring-1 focus:ring-blue-500 shadow-sm flex justify-between items-center transition active:scale-[0.98]">
                      <span class="truncate">{{ trendFiles.length === 0 ? 'Semua File' : trendFiles.length + ' File' }}</span>
                      <svg class="w-4 h-4 text-slate-500 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                    </button>
                    <div v-if="isTrendFileFilterOpen" class="absolute z-10 w-full mt-1 bg-white border border-slate-200 rounded-lg shadow-lg max-h-60 overflow-y-auto">
                      <div class="p-2 space-y-1">
                        <label class="flex items-center gap-2 cursor-pointer p-1.5 hover:bg-slate-50 rounded">
                          <input type="checkbox" :checked="trendFiles.length === 0" @change="trendFiles = []" class="rounded border-slate-300 text-theme-brand-secondary focus:ring-theme-brand-secondary">
                          <span class="text-xs font-medium text-slate-700">Semua File</span>
                        </label>
                        <div class="border-t border-slate-100 my-1"></div>
                        <label v-for="file in availableTrendFiles" :key="file" class="flex items-center gap-2 cursor-pointer p-1.5 hover:bg-slate-50 rounded">
                          <input type="checkbox" :value="file" v-model="trendFiles" class="rounded border-slate-300 text-theme-brand-secondary focus:ring-theme-brand-secondary">
                          <span class="text-xs font-medium text-slate-700 truncate" :title="file">{{ file }}</span>
                        </label>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="flex items-center gap-2 min-w-[280px]">
                  <VueDatePicker
                    v-model="trendDateRange"
                    range
                    multi-calendars
                    :enable-time-picker="false"
                    placeholder="Pilih Rentang Waktu"
                    class="w-64"
                  />
                </div>
              </div>
              
              <!-- KPI Hero Cards -->
              <div v-if="trendRawData && trendRawData.kpi" class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-theme-surface border border-theme-border-subtle p-4 rounded-xl shadow-sm">
                  <div class="text-[11px] text-theme-text-secondary font-semibold uppercase tracking-wider mb-1">Total Paket</div>
                  <div class="text-2xl font-bold text-theme-text-primary">{{ trendRawData.kpi.total_packages || 0 }}</div>
                </div>
                <div class="bg-theme-surface border border-theme-border-subtle p-4 rounded-xl shadow-sm">
                  <div class="text-[11px] text-theme-text-secondary font-semibold uppercase tracking-wider mb-1">Total Scan</div>
                  <div class="text-2xl font-bold text-theme-text-primary">{{ trendRawData.kpi.total_scans || 0 }}</div>
                </div>
                <div class="bg-theme-surface border border-theme-border-subtle p-4 rounded-xl shadow-sm">
                  <div class="text-[11px] text-theme-text-secondary font-semibold uppercase tracking-wider mb-1">Avg Price</div>
                  <div class="text-2xl font-bold text-theme-text-primary">Rp{{ trendRawData.kpi.avg_price ? (trendRawData.kpi.avg_price / 1000).toFixed(0) + 'k' : 0 }}</div>
                </div>
                <div class="bg-theme-surface border border-theme-border-subtle p-4 rounded-xl shadow-sm">
                  <div class="text-[11px] text-theme-text-secondary font-semibold uppercase tracking-wider mb-1">Avg Yield</div>
                  <div class="text-2xl font-bold text-theme-text-primary">Rp{{ trendRawData.kpi.avg_yield || 0 }}/GB</div>
                </div>
              </div>

              <!-- Custom Interactive Legend for Market Trend -->
              <div v-if="trendProviders.length > 0" class="flex flex-wrap items-center justify-center gap-4 mb-6 px-4 py-3 bg-theme-surface border border-theme-border-subtle rounded-xl shadow-sm">
                <div class="flex flex-wrap justify-center items-center gap-2.5">
                  <!-- Grouped Providers -->
                  <div v-for="group in providerGroups" :key="group.parent" class="flex items-stretch rounded-lg overflow-hidden border border-slate-200/60 shadow-xs" :style="{ backgroundColor: group.bgColor }">
                    <button @click="toggleTrendProviderGroup(group.parent)" class="px-3 py-1.5 text-xs font-extrabold flex items-center justify-center transition-opacity hover:opacity-90" :style="{ backgroundColor: group.parentColor, color: group.parentTextColor || '#fff' }">
                      {{ group.parent }}
                    </button>
                    <button
                      v-for="child in group.children"
                      :key="child.value"
                      @click="toggleTrendProvider(child.value)"
                      class="px-3 py-1.5 text-xs font-extrabold transition-all duration-200 border-l border-slate-200/30 flex items-center gap-2"
                      :class="isTrendProviderActive(child.value) ? 'opacity-100' : 'opacity-40 hover:opacity-60'"
                      :style="{ color: '#1e293b' }"
                    >
                      <span>{{ child.name }}</span>
                    </button>
                  </div>
                  
                  <!-- Standalone Providers -->
                  <button
                    v-for="prov in trendProviders.filter(p => !providerGroups.some(g => g.children.some(c => c.value === p)))"
                    :key="prov"
                    @click="toggleTrendProvider(prov)"
                    class="flex items-center gap-2 px-3 py-1.5 rounded-lg text-xs font-extrabold transition-all duration-200 border active:scale-[0.98] transition-transform"
                    :class="isTrendProviderActive(prov)
                      ? 'bg-theme-bg-primary text-theme-text-primary border-slate-300 shadow-sm ring-1 ring-slate-200/50'
                      : 'bg-slate-200/60 text-slate-400 border-transparent opacity-60 hover:opacity-80'"
                  >
                    <span class="w-2.5 h-2.5 rounded-full shadow-sm" :style="{ backgroundColor: isTrendProviderActive(prov) ? getProviderColor(prov) : '#94a3b8' }"></span>
                    <span>{{ prov }}</span>
                  </button>
                </div>
              </div>

              <div class="w-full h-80 relative">
                <div v-if="trendLoading" class="absolute inset-0 flex items-center justify-center bg-white/80 backdrop-blur-sm z-10 rounded-xl">
                  <svg class="animate-spin h-8 w-8 text-blue-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                </div>
                <Line v-if="trendChartData.labels.length > 0" :data="trendChartData" :options="trendChartOptions" />
                <div v-else-if="!trendLoading" class="flex flex-col items-center justify-center h-full text-slate-400 italic p-4 text-center">
                  <p>Belum ada data trend untuk rentang waktu ini.</p>
                </div>
              </div>
            </div>

            <!-- Extracted Data Table Widget -->
            <div
              v-if="
                activeSession.packages &&
                activeSession.packages.length > 0
              "
              class="anim-on-scroll opacity-0 bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-md mb-8"
            >
              <div
                class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center"
              >
                <h3
                  class="font-semibold text-slate-800 flex items-center gap-2"
                >
                  <div
                    class="w-2 h-2 rounded-full bg-green-500 shadow-xs"
                  ></div>
                  Data Berhasil Diekstrak ({{
                    filteredPackagesList.length
                  }} dari {{ activeSession.packages.length }} paket)
                </h3>
                <div class="flex items-stretch gap-3">
                  <div
                    v-if="activeSession.performance_metrics"
                    class="hidden md:flex items-center gap-2 text-[10px] bg-slate-100 rounded-lg px-2 py-1 border border-slate-200 my-auto shadow-xs"
                  >
                    <span
                      class="text-slate-600 font-medium"
                      title="Waktu Ekstraksi Vision AI"
                      >⏱️ Ekstraksi:
                      <span class="text-indigo-700 font-bold"
                        >{{
                          activeSession
                            .performance_metrics
                            .extract_time
                        }}s</span
                      ></span
                    >
                    <span class="text-slate-300">|</span>
                    <span
                      class="text-slate-600 font-medium"
                      title="Waktu Pembuatan Benchmarking"
                      >Analisis:
                      <span class="text-indigo-700 font-bold"
                        >{{
                          activeSession
                            .performance_metrics
                            .chat_time
                        }}s</span
                      ></span
                    >
                    <span class="text-slate-300">|</span>
                    <span
                      class="text-slate-600 font-medium"
                      title="Total Waktu"
                      >Total:
                      <span
                        class="text-blue-600 font-extrabold"
                        >{{
                          activeSession
                            .performance_metrics
                            .total_time
                        }}s</span
                      ></span
                    >
                  </div>

                  <button
                    v-if="comparisonResults[activeSession.id]"
                    @click="syncAllCsv(activeSession)"
                    :disabled="isComparing[activeSession.id]"
                    class="text-xs px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 rounded-md text-white transition border border-indigo-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm active:scale-[0.98] transition-transform"
                    :class="{'opacity-50 cursor-not-allowed': isComparing[activeSession.id]}"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                    <span v-if="!isComparing[activeSession.id]">Samakan Semua Data</span>
                    <span v-else>Memproses...</span>
                  </button>
                  <label class="text-xs px-3 py-1.5 bg-yellow-600 hover:bg-yellow-500 rounded-md text-white transition border border-yellow-500 flex items-center justify-center gap-1 text-center font-medium cursor-pointer shadow-sm"
                    :class="{'opacity-50 cursor-not-allowed': isComparing[activeSession.id]}"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                    <span v-if="!isComparing[activeSession.id]">Bandingkan Data</span>
                    <span v-else>Memproses...</span>
                    <input type="file" class="hidden" accept=".csv,.xlsx,.xls" @change="uploadCsvForComparison($event, activeSession.id)" :disabled="isComparing[activeSession.id]">
                  </label>
                  <button
                    v-if="comparisonResults[activeSession.id]"
                    @click="clearFlags(activeSession.id)"
                    class="text-xs px-3 py-1.5 bg-red-600 hover:bg-red-500 rounded-md text-white transition border border-red-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm active:scale-[0.98] transition-transform"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                    Hapus Flag
                  </button>
                  <button
                    @click="isFilterOpen = !isFilterOpen"
                    class="text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-500 rounded-md text-white transition border border-blue-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm active:scale-[0.98] transition-transform"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                    Filter
                  </button>
                  <button
                    v-if="!isEditingTable[activeSession.id]"
                    @click="toggleEditTable(activeSession.id, filteredPackagesList)"
                    class="text-xs px-3 py-1.5 bg-orange-500 hover:bg-orange-400 rounded-md text-white transition border border-orange-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm active:scale-[0.98] transition-transform"
                  >
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path></svg>
                    Edit Data
                  </button>
                  <button
                    v-if="isEditingTable[activeSession.id]"
                    @click="savePackages(activeSession.id)"
                    class="text-xs px-3 py-1.5 bg-green-600 hover:bg-green-500 rounded-md text-white transition border border-green-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm active:scale-[0.98] transition-transform"
                  >
                    <svg v-if="savingTable[activeSession.id]" class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                    <svg v-else class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    Simpan Perubahan
                  </button>
                  <button
                    v-if="isEditingTable[activeSession.id]"
                    @click="toggleEditTable(activeSession.id, filteredPackagesList)"
                    class="text-xs px-3 py-1.5 bg-slate-500 hover:bg-slate-400 rounded-md text-white transition border border-slate-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm active:scale-[0.98] transition-transform"
                  >
                    Batal
                  </button>

                  <button
                    @click="toggleTable(activeSession.id)"
                    class="text-xs px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-md text-slate-700 font-semibold transition border border-slate-300 flex items-center justify-center h-full text-center shadow-xs active:scale-[0.98] transition-transform"
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
                    class="flex flex-col xl:flex-row border-t border-slate-200"
                  >
                    <!-- Filter Sidebar -->
                    <div v-if="isFilterOpen" class="w-full xl:w-1/4 p-4 bg-slate-50 border-b xl:border-b-0 xl:border-r border-slate-200 overflow-y-auto custom-scrollbar flex-shrink-0">
                      <div class="flex items-center justify-between mb-4">
                        <h4 class="text-sm text-slate-800 font-bold flex items-center gap-2">
                          <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                          Filter Data
                        </h4>
                        <button @click="resetFilters" class="text-xs text-blue-600 font-bold hover:underline active:scale-[0.98] transition-transform">Reset</button>
                      </div>

                      <div class="space-y-5">
                        <!-- Status Flag -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Status Flag</h5>
                          <div class="space-y-1">
                            <label class="flex items-center gap-2 cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900">
                              <input type="checkbox" value="price_mismatch" v-model="filters.flags" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                              <span class="w-2.5 h-2.5 rounded-full bg-yellow-500 shadow-xs inline-block"></span>
                              Harga Berbeda
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900">
                              <input type="checkbox" value="not_found" v-model="filters.flags" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                              <span class="w-2.5 h-2.5 rounded-full bg-red-500 shadow-xs inline-block"></span>
                              Tidak Ditemukan
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900">
                              <input type="checkbox" value="synced" v-model="filters.flags" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                              <span class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-xs inline-block"></span>
                              Telah Disamakan
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900">
                              <input type="checkbox" value="is_new_product" v-model="filters.flags" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                              <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700 border border-green-200">PRODUK BARU</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900">
                              <input type="checkbox" value="is_price_changed" v-model="filters.flags" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                              <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700 border border-orange-200">HARGA BEDA</span>
                            </label>
                          </div>
                        </div>

                        <!-- Provider -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Provider</h5>
                          <div class="space-y-1">
                            <label v-for="prov in availableProviders" :key="prov" class="flex items-center gap-2 cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900">
                              <input type="checkbox" :value="prov" v-model="filters.providers" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                              {{ prov }}
                            </label>
                          </div>
                        </div>

                        <!-- Lokasi -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Lokasi</h5>
                          <input type="text" v-model="filters.location" placeholder="Cari lokasi..." class="w-full bg-theme-surface border border-theme-border-default rounded px-3 py-1.5 text-xs text-theme-text-primary focus:border-theme-brand-secondary outline-none placeholder-theme-text-muted shadow-sm" />
                        </div>

                        <!-- Kategori -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Kategori</h5>
                          <div class="space-y-1">
                            <label v-for="cat in availableCategories" :key="cat" class="flex items-center gap-2 cursor-pointer text-sm font-medium text-slate-700 hover:text-slate-900">
                              <input type="checkbox" :value="cat" v-model="filters.categories" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                              {{ cat }}
                            </label>
                          </div>
                        </div>

                        <!-- Nama Paket -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Nama Paket</h5>
                          <input type="text" v-model="filters.search" placeholder="Cari nama paket..." class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                        </div>

                        <!-- Timestamp -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Timestamp</h5>
                          <input type="text" v-model="filters.dateStart" placeholder="Cari timestamp (mis. 2024-07)..." class="w-full bg-white border border-slate-300 rounded px-3 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                        </div>

                        <!-- Kuota Range -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Range Kuota (GB)</h5>
                          <div class="flex items-center gap-2">
                            <input type="number" v-model="filters.gbMin" placeholder="Min" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                            <span class="text-slate-400">-</span>
                            <input type="number" v-model="filters.gbMax" placeholder="Max" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                          </div>
                        </div>

                        <!-- Harga Range -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Range Harga (Rp)</h5>
                          <div class="flex items-center gap-2">
                            <input type="number" v-model="filters.priceMin" placeholder="Min" class="w-full bg-theme-surface border border-theme-border-default rounded px-2 py-1.5 text-xs text-theme-text-primary focus:border-theme-brand-secondary outline-none placeholder-theme-text-muted shadow-sm" />
                            <span class="text-theme-text-muted">-</span>
                            <input type="number" v-model="filters.priceMax" placeholder="Max" class="w-full bg-theme-surface border border-theme-border-default rounded px-2 py-1.5 text-xs text-theme-text-primary focus:border-theme-brand-secondary outline-none placeholder-theme-text-muted shadow-sm" />
                          </div>
                        </div>

                        <!-- Masa Aktif Range -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Masa Aktif (Hari)</h5>
                          <div class="flex items-center gap-2">
                            <input type="number" v-model="filters.daysMin" placeholder="Min" class="w-full bg-theme-surface border border-theme-border-default rounded px-2 py-1.5 text-xs text-theme-text-primary focus:border-theme-brand-secondary outline-none placeholder-theme-text-muted shadow-sm" />
                            <span class="text-theme-text-muted">-</span>
                            <input type="number" v-model="filters.daysMax" placeholder="Max" class="w-full bg-theme-surface border border-theme-border-default rounded px-2 py-1.5 text-xs text-theme-text-primary focus:border-theme-brand-secondary outline-none placeholder-theme-text-muted shadow-sm" />
                          </div>
                        </div>

                        <!-- Yield Range -->
                        <div>
                          <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Range Yield (Rp/GB)</h5>
                          <div class="flex items-center gap-2">
                            <input type="number" v-model="filters.yieldMin" placeholder="Min" class="w-full bg-theme-surface border border-theme-border-default rounded px-2 py-1.5 text-xs text-theme-text-primary focus:border-theme-brand-secondary outline-none placeholder-theme-text-muted shadow-sm" />
                            <span class="text-theme-text-muted">-</span>
                            <input type="number" v-model="filters.yieldMax" placeholder="Max" class="w-full bg-theme-surface border border-theme-border-default rounded px-2 py-1.5 text-xs text-theme-text-primary focus:border-theme-brand-secondary outline-none placeholder-theme-text-muted shadow-sm" />
                          </div>
                        </div>
                      </div>
                    </div>

                <!-- LEFT: Source Images (Only show when editing for easier crosscheck) -->
                <div
                  v-if="isEditingTable[activeSession.id]"
                  class="w-full xl:w-1/3 p-4 bg-theme-secondary border-b xl:border-b-0 xl:border-r border-theme-border-subtle max-h-[600px] overflow-y-auto custom-scrollbar"
                >
                  <h4
                    class="text-[11px] text-theme-text-primary font-bold mb-4 uppercase tracking-wider sticky top-0 bg-theme-secondary py-2 z-10"
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
                            class="rounded-lg border border-theme-border-default hover:border-theme-brand-secondary transition cursor-zoom-in w-full object-contain bg-theme-surface shadow-sm"
                            @click="openImage(att)"
                            title="Klik untuk memperbesar"
                          />
                          <div
                            v-else
                            class="text-[11px] text-theme-text-primary font-medium p-2 bg-theme-surface rounded border border-theme-border-default shadow-sm break-all flex items-center gap-2"
                          >
                            <svg
                              class="w-4 h-4 shrink-0 text-theme-brand-secondary"
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
                  :class="[
                    isEditingTable[activeSession.id] ? 'xl:w-2/3' : '',
                    isFilterOpen && !isEditingTable[activeSession.id] ? 'xl:w-3/4' : ''
                  ]"
                >
                  <table
                    class="w-full text-sm text-left text-slate-700"
                  >
                    <thead
                      class="text-[11px] text-theme-text-secondary uppercase bg-theme-secondary border-b border-theme-border-subtle font-bold"
                    >
                      <tr>
                        <th
                          v-if="
                            isEditingTable[
                              activeSession.id
                            ]
                          "
                          class="px-4 py-3"
                        >
                          <input type="checkbox" :checked="selectedEditRows[activeSession.id]?.length === editablePackages[activeSession.id]?.length && editablePackages[activeSession.id]?.length > 0" @change="toggleSelectAllEdit(activeSession.id, $event)" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                        </th>
                        <th class="px-4 py-3">
                          TimeStamp
                        </th>
                        <th class="px-4 py-3">
                          Lokasi
                        </th>
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
                        v-for="pkg in filteredPackagesList"
                        :key="pkg.id"
                        class="border-b border-theme-border-subtle transition-colors cursor-pointer hover:bg-theme-secondary/80"
                        @click="openEditModal(pkg, activeSession.id)"
                      >
                        <td class="px-4 py-3 text-slate-600 text-xs truncate max-w-[150px]" :title="pkg.image_timestamp || pkg.created_at">
                          {{ pkg.image_timestamp || (pkg.created_at ? pkg.created_at.replace('T', ' ').substring(0, 19) : '-') }}
                        </td>
                        <td class="px-4 py-3 text-slate-600 text-xs truncate max-w-[150px]" :title="pkg.branch || pkg.image_location || '-'">
                          {{ pkg.branch || pkg.image_location || '-' }}
                        </td>
                        <td
                          class="px-4 py-3 font-bold text-slate-800 transition-colors"
                        >
                          <div class="flex items-center gap-2">
                            <span>{{ pkg.provider }}</span>
                            <template v-if="comparisonResults[activeSession.id] && comparisonResults[activeSession.id][pkg.id]">
                              <span v-if="comparisonResults[activeSession.id][pkg.id].status === 'price_mismatch'" :title="'Harga berbeda. CSV: Rp ' + Number(comparisonResults[activeSession.id][pkg.id].expected_price).toLocaleString('id-ID')" class="w-2.5 h-2.5 rounded-full bg-yellow-500 shadow-[0_0_8px_rgba(234,179,8,0.6)]"></span>
                              <span v-else-if="comparisonResults[activeSession.id][pkg.id].status === 'not_found'" title="Tidak ditemukan di CSV" class="w-2.5 h-2.5 rounded-full bg-red-500 shadow-[0_0_8px_rgba(239,68,68,0.6)]"></span>
                              <span v-else-if="comparisonResults[activeSession.id][pkg.id].status === 'synced'" title="Telah disamakan dengan CSV" class="w-2.5 h-2.5 rounded-full bg-blue-500 shadow-[0_0_8px_rgba(59,130,246,0.6)]"></span>
                            </template>
                          </div>
                        </td>
                        <td class="px-4 py-3 text-slate-700">
                          <div class="flex items-center gap-2">
                            <span>{{ pkg.package_name || '-' }}</span>
                            <span v-if="pkg.is_new_product" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-green-100 text-green-700 border border-green-200 whitespace-nowrap" title="Tidak ada di baseline (List produk.csv)">PRODUK BARU</span>
                          </div>
                        </td>
                        <td
                          class="px-4 py-3 text-right font-bold text-blue-600"
                        >
                          {{ pkg.gb }} GB
                        </td>
                        <td class="px-4 py-3 text-right text-slate-600">
                          <div class="flex flex-col items-end gap-1">
                            <span v-if="pkg.is_days_changed" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-yellow-100 text-yellow-700 border border-yellow-200 whitespace-nowrap" :title="'Masa aktif baseline: ' + pkg.baseline_days + ' Hari'">MASA AKTIF BEDA</span>
                            <span>{{ pkg.days }} Hari</span>
                          </div>
                        </td>
                        <td
                          class="px-4 py-3 text-right text-slate-800 font-medium"
                        >
                          <div class="flex flex-col items-end gap-1">
                            <span v-if="pkg.is_price_changed" class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-orange-100 text-orange-700 border border-orange-200 whitespace-nowrap" :title="'Harga baseline: Rp ' + Number(pkg.baseline_price).toLocaleString('id-ID')">HARGA BEDA</span>
                            <span>Rp {{ Number(pkg.price).toLocaleString("id-ID") }}</span>
                          </div>
                        </td>
                        <td
                          class="px-4 py-3 text-slate-600"
                        >
                          <span
                            class="px-2.5 py-1 bg-slate-100 rounded-full text-[10px] font-bold text-slate-700 border border-slate-200 shadow-xs"
                            >{{
                              pkg.category
                            }}</span
                          >
                        </td>
                        <td
                          class="px-4 py-3 text-right font-bold text-xs"
                          :class="{
                            'text-emerald-600 font-extrabold':
                              pkg.yield_val <
                              3000,
                            'text-amber-600 font-extrabold':
                              pkg.yield_val >=
                                3000 &&
                              pkg.yield_val <=
                                5000,
                            'text-rose-600 font-extrabold':
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
                        class="border-b border-theme-border-default bg-theme-surface hover:bg-theme-secondary/80"
                      >
                        <td class="px-2 py-2 text-center">
                          <input type="checkbox" :value="idx" v-model="selectedEditRows[activeSession.id]" class="rounded bg-white border-slate-300 text-blue-600 focus:ring-blue-500/50 cursor-pointer shadow-xs" />
                        </td>
                        <td class="px-2 py-2">
                          <div class="text-xs text-slate-500 truncate max-w-[100px]" :title="pkg.image_timestamp">{{ pkg.image_timestamp || '-' }}</div>
                        </td>
                        <td class="px-2 py-2">
                          <div class="text-xs text-slate-500 truncate max-w-[100px]" :title="pkg.branch || pkg.image_location || '-'">{{ pkg.branch || pkg.image_location || '-' }}</div>
                        </td>
                        <td class="px-2 py-2">
                          <input
                            type="text"
                            v-model="pkg.provider"
                            class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none shadow-sm"
                          />
                        </td>
                        <td class="px-2 py-2">
                          <input
                            type="text"
                            v-model="pkg.package_name"
                            placeholder="Nama (opsional)"
                            class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none shadow-sm"
                          />
                        </td>
                        <td
                          class="px-2 py-2 text-right"
                        >
                          <input
                            type="number"
                            step="0.1"
                            v-model="pkg.gb"
                            class="w-full max-w-[80px] bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none text-right shadow-sm"
                          />
                        </td>
                        <td
                          class="px-2 py-2 text-right"
                        >
                          <input
                            type="number"
                            v-model="pkg.days"
                            class="w-full max-w-[80px] bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none text-right shadow-sm"
                          />
                        </td>
                        <td
                          class="px-2 py-2 text-right"
                        >
                          <input
                            type="number"
                            v-model="pkg.price"
                            class="w-full max-w-[100px] bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none text-right shadow-sm"
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
                              class="text-green-700 hover:text-green-800 p-1.5 bg-green-50 hover:bg-green-100 border border-green-200 rounded transition shadow-xs active:scale-[0.98] transition-transform"
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
                              class="text-red-700 hover:text-red-800 p-1.5 bg-red-50 hover:bg-red-100 border border-red-200 rounded transition shadow-xs active:scale-[0.98] transition-transform"
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
                          class="px-4 py-4 bg-slate-50 border-t border-slate-200"
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
                              class="px-3 py-1.5 bg-white hover:bg-slate-100 border border-slate-300 rounded text-xs font-semibold text-slate-700 transition flex items-center gap-1 shadow-sm active:scale-[0.98] transition-transform"
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
                              class="px-4 py-1.5 bg-blue-600 hover:bg-blue-500 rounded text-sm text-white font-semibold transition shadow-md flex items-center gap-2 active:scale-[0.98] transition-transform"
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
            <!-- Baseline Products Widget -->
            <div
              class="anim-on-scroll bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-md mb-8"
            >
              <div
                class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center"
              >
                <h3
                  class="font-semibold text-slate-800 flex items-center gap-2"
                >
                  <div
                    class="w-2 h-2 rounded-full bg-blue-500 shadow-xs"
                  ></div>
                  Data Produk Baseline ({{ baselineProducts.length }} produk)
                </h3>
                <div class="flex items-stretch gap-3">
                  <button
                    @click="toggleEditBaselineTable"
                    class="text-xs px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-md text-slate-700 font-semibold transition border border-slate-300 flex items-center justify-center h-full text-center shadow-xs active:scale-[0.98] transition-transform"
                  >
                    {{
                      isEditingBaseline
                        ? "Sembunyikan Tabel"
                        : "Lihat Tabel / Edit"
                    }}
                  </button>
                </div>
              </div>

              <div
                v-show="isEditingBaseline"
                class="flex flex-col xl:flex-row border-t border-slate-200"
              >
                <div class="w-full overflow-x-auto">
                  <table class="w-full text-sm text-left text-slate-700">
                    <thead class="text-[11px] text-theme-text-secondary uppercase bg-theme-secondary border-b border-theme-border-subtle font-bold">
                      <tr>
                        <th class="px-4 py-3">Kriteria</th>
                        <th class="px-4 py-3">Provider</th>
                        <th class="px-4 py-3">Nama Paket</th>
                        <th class="px-4 py-3 text-right">RBP VORI</th>
                        <th class="px-4 py-3 text-right">RBP Rebuy</th>
                        <th class="px-4 py-3 text-right">RBP Inject</th>
                        <th class="px-4 py-3 text-right">Harga (Rp)</th>
                        <th class="px-4 py-3 text-right">Kuota S</th>
                        <th class="px-4 py-3 text-right">Kuota E</th>
                        <th class="px-4 py-3 text-right">Kuota A</th>
                        <th class="px-4 py-3 text-right">Masa Aktif</th>
                        <th class="px-4 py-3 text-center">Aksi</th>
                      </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                      <tr
                        v-for="(pkg, idx) in editableBaselineProducts"
                        :key="'b_' + idx"
                        class="hover:bg-slate-50 transition-colors group"
                      >
                        <td class="px-4 py-2">
                          <input v-model="pkg.criteria" class="w-full bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2">
                          <input v-model="pkg.provider" class="w-20 font-bold bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2">
                          <input v-model="pkg.package_name" class="w-full font-medium bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2">
                          <input v-model="pkg.rbp_vori" placeholder="-" class="w-16 font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1 text-right" />
                        </td>
                        <td class="px-4 py-2">
                          <input v-model="pkg.rbp_rebuy" placeholder="-" class="w-16 font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1 text-right" />
                        </td>
                        <td class="px-4 py-2">
                          <input v-model="pkg.rbp_inject" placeholder="-" class="w-16 font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1 text-right" />
                        </td>
                        <td class="px-4 py-2">
                          <input type="number" v-model="pkg.price" class="w-24 text-right font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2">
                          <input type="number" v-model="pkg.quota_s" class="w-16 text-right font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2">
                          <input type="number" v-model="pkg.quota_e" class="w-16 text-right font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2">
                          <input type="number" v-model="pkg.quota_a" class="w-16 text-right font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2">
                          <input type="number" v-model="pkg.days" class="w-16 text-right font-mono bg-transparent border-0 border-b border-transparent focus:border-blue-500 focus:ring-0 text-xs px-0 py-1" />
                        </td>
                        <td class="px-4 py-2 text-center align-middle">
                          <button
                            @click="deleteBaselineRow(idx)"
                            class="text-red-700 hover:text-red-800 p-1.5 bg-red-50 hover:bg-red-100 border border-red-200 rounded transition shadow-xs active:scale-[0.98] transition-transform"
                            title="Hapus Baris"
                          >
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                          </button>
                        </td>
                      </tr>
                      <tr>
                        <td colspan="12" class="px-4 py-4 bg-slate-50 border-t border-slate-200">
                          <div class="flex justify-between items-center">
                            <button
                              @click="addEmptyBaselineRow"
                              class="px-3 py-1.5 bg-white hover:bg-slate-100 border border-slate-300 rounded text-xs font-semibold text-slate-700 transition flex items-center gap-1 shadow-sm active:scale-[0.98] transition-transform"
                            >
                              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                              Tambah Data Manual
                            </button>
                            <button
                              @click="saveBaselineProducts"
                              class="px-4 py-1.5 bg-blue-600 hover:bg-blue-500 rounded text-sm text-white font-semibold transition shadow-md flex items-center gap-2 active:scale-[0.98] transition-transform"
                              :disabled="savingBaseline"
                            >
                              <svg v-if="savingBaseline" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
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
            
            <!-- Rejected Packages Table -->
            <div v-if="rejectedPackages.length > 0" class="mt-8 border-t-2 border-theme-semantic-danger pt-6">
              <div class="flex items-center gap-2 mb-4">
                <h3 class="text-lg font-bold text-theme-semantic-danger">Paket Ditolak (Anomali Harga/Kuota/Hari)</h3>
              </div>
              <div class="bg-theme-semantic-danger/5 border border-theme-semantic-danger/20 rounded-xl overflow-hidden shadow-sm">
                <table class="w-full text-left border-collapse text-sm">
                  <thead>
                    <tr class="bg-theme-semantic-danger/10 text-theme-text-primary border-b border-theme-semantic-danger/20">
                      <th class="px-4 py-3 font-semibold uppercase tracking-wider text-[11px]">Provider</th>
                      <th class="px-4 py-3 font-semibold uppercase tracking-wider text-[11px]">Nama Paket</th>
                      <th class="px-4 py-3 font-semibold uppercase tracking-wider text-[11px]">Harga Mentah</th>
                      <th class="px-4 py-3 font-semibold uppercase tracking-wider text-[11px]">Kuota Mentah</th>
                      <th class="px-4 py-3 font-semibold uppercase tracking-wider text-[11px]">Hari Mentah</th>
                      <th class="px-4 py-3 font-semibold uppercase tracking-wider text-[11px] text-right">Aksi</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(res, i) in rejectedPackages" :key="res.id" class="border-b border-theme-semantic-danger/10 hover:bg-theme-semantic-danger/10">
                      <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-theme-secondary text-theme-text-primary">{{ res.provider }}</span>
                      </td>
                      <td class="px-4 py-3 font-medium text-theme-text-primary">{{ res.package_name || '-' }}</td>
                      <td class="px-4 py-3 font-mono text-theme-semantic-danger">{{ res.price }}</td>
                      <td class="px-4 py-3 font-mono text-theme-semantic-danger">{{ res.gb }}</td>
                      <td class="px-4 py-3 font-mono text-theme-semantic-danger">{{ res.days }}</td>
                      <td class="px-4 py-3 text-right">
                        <button @click="openEditModal(res, activeSession.id)" class="text-xs bg-theme-semantic-danger/20 text-theme-semantic-danger hover:bg-theme-semantic-danger/30 px-3 py-1.5 rounded-lg font-bold transition active:scale-[0.98]">Perbaiki</button>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- Knowledge Base AI (Learned Patterns) -->
            <div v-if="learnedPatterns.length > 0" class="mt-8 border-t-2 border-theme-semantic-info pt-6">
              <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                  <svg class="w-6 h-6 text-theme-semantic-info" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path>
                  </svg>
                  <h3 class="text-lg font-bold text-theme-semantic-info">Knowledge Base AI (Pola yang Dipelajari)</h3>
                </div>
                <span class="text-[11px] text-theme-text-muted bg-theme-secondary px-2 py-1 rounded-full border border-theme-border-subtle">Sistem akan secara otomatis belajar dari koreksi CSV Anda</span>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="(pattern, i) in learnedPatterns" :key="i" class="bg-theme-semantic-info/5 border border-theme-semantic-info/20 rounded-xl p-4 shadow-sm hover:shadow-md transition">
                  <div class="flex items-center justify-between mb-2">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold bg-theme-semantic-info text-white">{{ pattern.provider }}</span>
                  </div>
                  <p class="text-sm text-theme-text-primary font-medium leading-snug">
                    {{ pattern.rule_text }}
                  </p>
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
                  class="bg-theme-surface p-6 rounded-2xl border border-theme-border-subtle shadow-sm flex flex-col h-full"
                >
                  <div class="flex items-center justify-between mb-4">
                    <div class="flex items-center gap-2">
                      <svg
                        class="w-5 h-5 text-indigo-600"
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
                      <h3 class="font-bold text-slate-800">
                        Visualisasi Data
                      </h3>
                    </div>
                    <button @click="deleteChart(activeSession, msg)" aria-label="Hapus Grafik" class="text-slate-400 hover:text-red-600 transition-colors p-1 rounded-full hover:bg-slate-100 active:scale-[0.98] transition-transform" title="Hapus Grafik">
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
      <!-- FLOATING CHAT WIDGET -->
      <div
        class="absolute bottom-6 right-6 z-50 flex flex-col items-end pointer-events-none"
      >
        <!-- Chat Window Pop-up -->
        <div
          v-show="isChatOpen"
          class="pointer-events-auto bg-white w-[400px] max-w-[calc(100vw-3rem)] h-[600px] max-h-[calc(100vh-8rem)] rounded-2xl shadow-[0_10px_50px_rgba(0,0,0,0.15)] border border-slate-200 flex flex-col mb-4 overflow-hidden origin-bottom-right transition-all"
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
            <button aria-label="Tutup Sidebar Chat"
              @click="isChatOpen = false"
              class="p-1.5 bg-white/10 hover:bg-white/20 rounded-lg text-white transition backdrop-blur-sm active:scale-[0.98] transition-transform"
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
            class="flex-1 overflow-y-auto p-4 space-y-4 custom-scrollbar bg-slate-50"
          >
            <div
              v-if="
                !activeSession ||
                activeSession.chat_messages?.length === 0
              "
              class="h-full flex flex-col items-center justify-center text-center opacity-70 px-4"
            >
              <svg class="w-16 h-16 text-slate-300 dark:text-slate-600 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
              <p class="text-sm font-semibold text-slate-600 dark:text-slate-400">
                Belum ada percakapan
              </p>
              <p class="text-xs text-slate-500 mt-1 max-w-[200px]">
                Upload gambar pricelist untuk memulai scan AI atau mulai obrolan baru.
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
                      : 'bg-gradient-to-br from-blue-600 to-purple-600'
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
                      : 'bg-white text-slate-800 rounded-tl-sm border border-slate-200 shadow-sm'
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
                      class="flex items-center gap-2 bg-slate-100 rounded-lg px-2.5 py-1.5 text-xs border border-slate-200 text-slate-700"
                    >
                      <svg
                        class="w-3.5 h-3.5 text-blue-600"
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
                      <span class="truncate font-medium">{{
                        attachment.split("/").pop()
                      }}</span>
                    </div>
                  </div>

                  <!-- Inform user that chart is on dashboard -->
                  <div
                    v-if="msg.chart_config"
                    class="text-xs bg-blue-50 text-blue-700 border border-blue-200 rounded-lg px-3 py-2 mb-2 flex items-center gap-2 font-semibold"
                  >
                    <svg
                      class="w-4 h-4 shrink-0 text-blue-600"
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
                    class="prose prose-sm max-w-none text-[13px] leading-relaxed break-words text-slate-800 font-medium"
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
                  class="w-7 h-7 shrink-0 rounded-full bg-gradient-to-br from-blue-600 to-purple-600 flex items-center justify-center mt-1 shadow-sm"
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
                  class="bg-white border border-slate-200 rounded-2xl rounded-tl-sm p-3.5 flex space-x-1.5 items-center w-fit shadow-sm"
                >
                  <div
                    class="w-1.5 h-1.5 bg-slate-400 rounded-full animate-bounce"
                  ></div>
                  <div
                    class="w-1.5 h-1.5 bg-slate-400 rounded-full animate-bounce"
                    style="animation-delay: 0.15s"
                  ></div>
                  <div
                    class="w-1.5 h-1.5 bg-slate-400 rounded-full animate-bounce"
                    style="animation-delay: 0.3s"
                  ></div>
                </div>
              </div>
            </template>
          </div>

      <!-- Global Error Toast -->
      <transition
        enter-active-class="transition ease-out duration-300"
        enter-from-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:translate-x-4"
        enter-to-class="transform opacity-100 translate-y-0 sm:translate-x-0"
        leave-active-class="transition ease-in duration-200"
        leave-from-class="transform opacity-100 translate-y-0 sm:translate-x-0"
        leave-to-class="transform opacity-0 translate-y-4 sm:translate-y-0 sm:translate-x-4"
      >
        <div v-if="globalNotification.show" class="fixed bottom-4 right-4 z-50 flex items-center p-4 mb-4 w-full max-w-sm rounded-xl shadow-2xl border" 
           :class="globalNotification.type === 'error' ? 'text-white bg-red-600 border-red-500' : 'text-slate-900 bg-white border-slate-200 dark:bg-slate-800 dark:border-slate-700'" 
           role="alert">
          <div class="inline-flex flex-shrink-0 justify-center items-center w-8 h-8 rounded-lg"
             :class="globalNotification.type === 'error' ? 'text-red-500 bg-red-100' : 'text-green-500 bg-green-100 dark:bg-green-900/30'">
            <svg v-if="globalNotification.type === 'error'" class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 20 20">
              <path d="M10 .5a9.5 9.5 0 1 0 9.5 9.5A9.51 9.51 0 0 0 10 .5Zm3.707 11.793a1 1 0 1 1-1.414 1.414L10 11.414l-2.293 2.293a1 1 0 0 1-1.414-1.414L8.586 10 6.293 7.707a1 1 0 0 1 1.414-1.414L10 8.586l2.293-2.293a1 1 0 0 1 1.414 1.414L11.414 10l2.293 2.293Z"/>
            </svg>
            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
          </div>
          <div class="ms-3 text-sm font-medium">{{ globalNotification.message }}</div>
          <button @click="globalNotification.show = false" type="button" class="ms-auto -mx-1.5 -my-1.5 rounded-lg focus:ring-2 p-1.5 inline-flex items-center justify-center h-8 w-8 active:scale-[0.98] transition-transform" 
              :class="globalNotification.type === 'error' ? 'bg-red-600 text-red-200 hover:text-white focus:ring-red-400 hover:bg-red-700' : 'bg-transparent text-slate-400 hover:text-slate-900 dark:hover:text-white hover:bg-slate-100 dark:hover:bg-slate-700'" 
              aria-label="Close">
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
            class="p-3 bg-white border-t border-slate-200 shrink-0 relative"
          >
            <div
              v-if="form.images.length > 0"
              class="absolute -top-10 left-3 right-3 bg-indigo-600/95 backdrop-blur text-white font-medium text-xs px-3 py-2 rounded-t-lg flex items-center justify-between border-t border-x border-indigo-500 shadow-md"
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
                class="p-1 hover:bg-white/20 rounded active:scale-[0.98] transition-transform"
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
              class="flex items-end gap-2 bg-slate-50 p-1.5 border border-slate-300 rounded-xl focus-within:border-indigo-500 focus-within:ring-1 focus-within:ring-indigo-500 transition-all shadow-inner"
            >
              <label
                class="cursor-pointer p-2 text-slate-500 hover:text-indigo-600 hover:bg-slate-200/60 rounded-lg transition shrink-0"
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
                class="flex-1 bg-transparent border-none text-slate-800 text-[13px] focus:ring-0 p-2 max-h-24 min-h-[40px] resize-none outline-none custom-scrollbar placeholder-slate-400"
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
                class="p-2.5 text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shrink-0 disabled:opacity-40 disabled:bg-slate-300 disabled:text-slate-500 transition shadow-xs active:scale-[0.98] transition-transform"
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
            <div class="text-center text-[10px] text-slate-400 font-medium mt-2">
              SmartScan AI dapat membuat kesalahan.
            </div>
          </div>
        </div>

        <!-- Floating Toggle Button -->
        <button
          @click="isChatOpen = !isChatOpen"
          class="pointer-events-auto w-14 h-14 bg-theme-brand-secondary rounded-full flex items-center justify-center text-white shadow-lg hover:scale-105 hover:shadow-xl transition-all relative active:scale-[0.98]"
        >
          <span
            v-if="
              !isChatOpen &&
              activeSession &&
              (activeSession.status === 'processing' ||
                chatLoading[activeSession.id])
            "
            class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full border-2 border-white animate-ping"
          ></span>
          <span
            v-if="
              !isChatOpen &&
              activeSession &&
              (activeSession.status === 'processing' ||
                chatLoading[activeSession.id])
            "
            class="absolute -top-1 -right-1 w-4 h-4 bg-red-500 rounded-full border-2 border-white"
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

    <!-- Active Session Upload Modal -->
    <div v-if="showActiveUploadModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
      <div class="bg-white dark:bg-slate-800 rounded-2xl shadow-xl w-full max-w-md overflow-hidden animate-fade-in-up">
        <div class="p-6 border-b border-slate-100 dark:border-slate-700 flex justify-between items-center">
          <h3 class="font-bold text-slate-800 dark:text-slate-100 text-lg">
            {{ activeUploadMode === 'image' ? 'Upload Gambar Pricelist' : 'Upload Data Excel/CSV' }}
          </h3>
          <button @click="showActiveUploadModal = false; form.images = []; form.locations = [];" class="text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>
        
        <div class="p-6 flex flex-col items-center">
          <!-- Image Mode UI -->
          <template v-if="activeUploadMode === 'image'">
            <div class="w-16 h-16 bg-theme-brand-primary/10 rounded-full flex items-center justify-center mb-4">
              <svg class="anime-svg-draw w-8 h-8 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
            </div>
            <h3 class="text-xl font-bold text-slate-800 dark:text-slate-100 mb-2">{{ form.images.length }} File Terpilih</h3>
            
            <!-- Progress Bar for Images -->
            <div class="w-full mt-0 mb-4">
              <div class="flex justify-between text-sm text-slate-500 dark:text-slate-400 mb-2">
                <span>Ukuran: {{ formatBytes(totalFileSize) }}</span>
                <span>Batas: 100 MB</span>
              </div>
              <div class="w-full bg-slate-200 dark:bg-slate-700 rounded-full h-3 overflow-hidden">
                <div 
                  class="h-full rounded-full transition-all duration-500"
                  :class="fileSizePercentage >= 100 ? 'bg-theme-semantic-danger' : 'bg-theme-brand-primary'"
                  :style="{ width: fileSizePercentage + '%' }"
                ></div>
              </div>
              <p v-if="fileSizePercentage >= 100" class="text-red-500 text-sm mt-2 text-center font-medium">Ukuran melebihi batas maksimal!</p>
            </div>

            <!-- File List with Location Selectors -->
            <div class="w-full max-w-md mb-4 flex flex-col pointer-events-auto">
              <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Pilih Lokasi (Opsional)</label>
              <div class="w-full max-h-40 overflow-y-auto pr-2 bg-slate-50 dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-700 p-2 shadow-inner">
                <div v-for="(file, index) in form.images" :key="index" class="flex flex-col mb-3 pb-3 border-b border-slate-200 dark:border-slate-700 last:mb-0 last:pb-0 last:border-0">
                  <div class="text-sm font-medium text-slate-700 dark:text-slate-300 truncate mb-1" :title="file.name">
                    {{ file.name }}
                  </div>
                  <div class="flex flex-col sm:flex-row gap-2 w-full mt-0">
                    <select v-model="selectedRegions[index]" @change="form.locations[index] = ''" class="flex-1 text-xs bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded px-2 py-1.5 focus:ring-primary focus:border-primary">
                      <option value="">-- Pilih Region --</option>
                      <option v-for="group in branchOptions" :key="group.group" :value="group.group">{{ group.group }}</option>
                    </select>
                    <div class="flex-1 w-full">
                      <input 
                        type="text"
                        v-model="form.locations[index]" 
                        :list="'branch-list-modal-' + index" 
                        :disabled="!selectedRegions[index]"
                        placeholder="-- Ketik / Pilih Branch --"
                        class="w-full text-xs bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded px-2 py-1.5 focus:ring-primary focus:border-primary disabled:opacity-50 disabled:cursor-not-allowed"
                      >
                      <datalist :id="'branch-list-modal-' + index">
                        <template v-if="selectedRegions[index]">
                          <option v-for="opt in branchOptions.find(g => g.group === selectedRegions[index])?.options || []" :key="opt" :value="opt"></option>
                        </template>
                      </datalist>
                    </div>
                  </div>
                </div>
              </div>
            </div>

          </template>

          <!-- Excel Mode UI -->
          <template v-else>
            <div class="w-16 h-16 bg-theme-brand-primary/10 rounded-full flex items-center justify-center mb-4">
              <svg class="anime-svg-draw w-8 h-8 text-secondary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
            </div>
            <h3 class="text-lg font-bold text-slate-800 dark:text-slate-100 mb-4 text-center break-words max-w-full">
              {{ form.images[0]?.name }}
            </h3>
            
            <!-- Location Selector for CSV -->
            <div class="w-full flex flex-col mb-4 pointer-events-auto">
              <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Pilih Lokasi (Opsional)</label>
              <div class="w-full bg-slate-50 dark:bg-slate-900 rounded-lg border border-slate-200 dark:border-slate-700 p-2 shadow-inner">
                <div class="flex flex-col sm:flex-row gap-2 w-full mt-0">
                  <select v-model="selectedRegions[0]" @change="form.locations[0] = ''" class="flex-1 text-xs bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded px-2 py-1.5 focus:ring-primary focus:border-primary">
                    <option value="">-- Pilih Region --</option>
                    <option v-for="group in branchOptions" :key="group.group" :value="group.group">{{ group.group }}</option>
                  </select>
                  <div class="flex-1 w-full">
                    <input 
                      type="text"
                      v-model="form.locations[0]" 
                      list="branch-list-modal-csv" 
                      :disabled="!selectedRegions[0]"
                      placeholder="-- Ketik / Pilih Branch --"
                      class="w-full text-xs bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 rounded px-2 py-1.5 focus:ring-primary focus:border-primary disabled:opacity-50 disabled:cursor-not-allowed"
                    >
                    <datalist id="branch-list-modal-csv">
                      <template v-if="selectedRegions[0]">
                        <option v-for="opt in branchOptions.find(g => g.group === selectedRegions[0])?.options || []" :key="opt" :value="opt"></option>
                      </template>
                    </datalist>
                  </div>
                </div>
              </div>
            </div>
          </template>
          
          <!-- Timestamp Override -->
          <div class="w-full flex flex-col mb-2">
            <label class="text-sm font-semibold text-slate-700 dark:text-slate-300 mb-1">Atur Waktu (Opsional)</label>
            <p class="text-xs text-slate-500 dark:text-slate-400 mb-2">Jika kosong, akan menggunakan waktu file/saat ini.</p>
            <VueDatePicker
              v-if="activeUploadMode === 'image'"
              v-model="form.manual_timestamp"
              :enable-time-picker="true"
              placeholder="Pilih Tanggal & Waktu"
            />
            <input 
              v-else 
              type="date" 
              v-model="form.manual_timestamp" 
              class="w-full bg-white dark:bg-slate-700 border border-slate-300 dark:border-slate-600 rounded-lg text-sm text-slate-800 dark:text-slate-100 px-3 py-2 focus:ring-primary focus:border-primary"
            />
          </div>
        </div>

        <div class="p-6 bg-slate-50 dark:bg-slate-750 border-t border-slate-100 dark:border-slate-700 flex gap-3 justify-end">
          <button 
            @click="showActiveUploadModal = false; form.images = [];" 
            class="px-4 py-2 rounded-lg border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors font-medium"
          >
            Batal
          </button>
          <button 
            @click="processActiveUpload" 
            :disabled="(activeUploadMode === 'image' && fileSizePercentage >= 100) || form.processing"
            class="px-6 py-2 rounded-lg bg-theme-brand-primary hover:bg-theme-brand-primary/90 text-white shadow-sm transition-all font-semibold flex items-center gap-2 disabled:opacity-50"
          >
            <svg v-if="form.processing" class="animate-spin h-4 w-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            Upload
          </button>
        </div>
      </div>
    </div>

    <!-- Edit Modal -->

    <div v-if="editModalPkg" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
      <div class="bg-white border border-slate-200 rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto custom-scrollbar">
        <div class="sticky top-0 bg-slate-50 border-b border-slate-200 px-6 py-4 flex items-center justify-between z-10">
          <h3 class="text-lg font-bold text-slate-800">Detail & Edit Data</h3>
          <button @click="closeEditModal" class="text-slate-400 hover:text-slate-700 transition active:scale-[0.98] transition-transform">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
          </button>
        </div>
        
        <div class="p-6 space-y-6">
          <!-- Edit Form -->
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1 uppercase">Provider</label>
              <input v-model="editModalPkg.provider" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition shadow-sm" />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1 uppercase">Nama Paket</label>
              <input v-model="editModalPkg.package_name" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition shadow-sm" />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1 uppercase">Kuota (GB)</label>
              <input type="number" step="0.1" v-model="editModalPkg.gb" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition shadow-sm" />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1 uppercase">Masa Aktif (Hari)</label>
              <input type="number" v-model="editModalPkg.days" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition shadow-sm" />
            </div>
            <div class="col-span-2">
              <label class="block text-xs font-bold text-slate-700 mb-1 uppercase">Harga (Rp)</label>
              <input type="number" v-model="editModalPkg.price" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition shadow-sm" />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1 uppercase">Tanggal Gambar Diambil</label>
              <input type="date" v-model="editModalPkg.image_date" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition shadow-sm" />
            </div>
            <div>
              <label class="block text-xs font-bold text-slate-700 mb-1 uppercase">Jam Gambar Diambil</label>
              <input type="time" step="1" v-model="editModalPkg.image_time" class="w-full bg-white border border-slate-300 rounded-md px-3 py-2 text-sm text-slate-800 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 outline-none transition shadow-sm" />
            </div>

          </div>

          <!-- Comparison Info (if available) -->
          <div v-if="comparisonResults[editModalListId] && comparisonResults[editModalListId][editModalPkg.id]" class="mt-6 border-t border-slate-200 pt-6">
            <div class="flex items-center justify-between mb-3">
              <h4 class="text-sm font-bold text-slate-800">Perbandingan dengan Data Input (CSV/Excel)</h4>
              <button 
                v-if="comparisonResults[editModalListId][editModalPkg.id].status !== 'matched' && comparisonResults[editModalListId][editModalPkg.id].status !== 'not_found'"
                @click="syncWithCsv" 
                class="px-3 py-1.5 text-xs font-semibold bg-theme-brand-secondary hover:bg-theme-brand-secondary/90 text-white rounded-md shadow-sm transition flex items-center gap-1.5 active:scale-[0.98] transition-transform">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path></svg>
                Samakan Data Input
              </button>
            </div>
            
            <div v-if="comparisonResults[editModalListId][editModalPkg.id].status === 'not_found'" class="bg-red-50 border border-red-200 rounded-md p-3 text-sm font-medium text-red-600">
              Data hasil AI ini tidak memiliki pasangan yang cocok di dalam file Ground Truth yang di-upload.
            </div>
            <div v-else-if="comparisonResults[editModalListId][editModalPkg.id].status === 'synced'" class="bg-blue-50 border border-blue-200 rounded-md p-3 text-sm font-medium text-blue-700">
              Data telah disamakan dengan Ground Truth.
            </div>
            <div v-else class="overflow-x-auto text-left">
              <table class="w-full text-sm text-slate-700">
                <thead>
                  <tr class="bg-slate-50 text-slate-600 font-bold border-b border-slate-200">
                    <th class="p-2 border border-slate-200 rounded-tl-md">Atribut</th>
                    <th class="p-2 border border-slate-200">Hasil AI (Current)</th>
                    <th class="p-2 border border-slate-200">Data Input</th>
                    <th class="p-2 border border-slate-200 rounded-tr-md text-center">Status</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="attr in [
                    { label: 'Provider', key: 'provider', format: v => v },
                    { label: 'Kuota (GB)', key: 'gb', format: v => v },
                    { label: 'Harga (Rp)', key: 'price', format: v => Number(v).toLocaleString('id-ID') },
                    { label: 'Masa Aktif', key: 'days', format: v => v + ' Hari' }
                  ]" :key="attr.key" class="border-b border-slate-100">
                    <td class="p-2 border border-slate-200 font-bold text-slate-800">{{ attr.label }}</td>
                    <td class="p-2 border border-slate-200">{{ attr.format(editModalPkg[attr.key]) }}</td>
                    <td class="p-2 border border-slate-200">{{ attr.format(comparisonResults[editModalListId][editModalPkg.id].csv_row[attr.key]) }}</td>
                    <td class="p-2 border border-slate-200 text-center">
                      {{ checkMatch(attr.key, editModalPkg[attr.key], comparisonResults[editModalListId][editModalPkg.id].csv_row[attr.key]) ? '✅' : '❌' }}
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="sticky bottom-0 bg-slate-50 border-t border-slate-200 px-6 py-4 flex items-center justify-end gap-3 z-10">
          <button @click="closeEditModal" class="px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 transition active:scale-[0.98] transition-transform">Batal</button>
          <button @click="saveRowEdit" :disabled="isSavingModal" class="px-4 py-2 text-sm font-semibold bg-theme-brand-secondary hover:bg-theme-brand-secondary/90 rounded-md text-white transition shadow-md flex items-center gap-2 active:scale-[0.98] transition-transform">
            <svg v-if="isSavingModal" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            Simpan Perubahan
          </button>
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
  background-color: #cbd5e1;
  border-radius: 20px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
  background-color: #94a3b8;
}

/* Tailwind typography styling for light mode */
:deep(.prose) {
  color: #1e293b;
}
:deep(.prose h1),
:deep(.prose h2),
:deep(.prose h3),
:deep(.prose h4) {
  color: #0f172a;
  font-weight: 700;
}
:deep(.prose a) {
  color: #2563eb;
}
:deep(.prose strong) {
  color: #0f172a;
  font-weight: 700;
}
:deep(.prose code) {
  color: #e11d48;
  background-color: #f1f5f9;
  padding: 0.125rem 0.25rem;
  border-radius: 0.25rem;
  border: 1px solid #e2e8f0;
}
</style>
