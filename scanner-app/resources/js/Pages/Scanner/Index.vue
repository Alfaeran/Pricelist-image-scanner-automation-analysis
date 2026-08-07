<script setup>
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
import { Head, useForm, router } from "@inertiajs/vue3";
import { ref, onMounted, onUnmounted, nextTick, computed, watch } from "vue";
import axios from "axios";
import ChartViewer from "@/Components/ChartViewer.vue";
import Swal from "sweetalert2";
import { Line, Bar, Scatter } from 'vue-chartjs'
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, Tooltip as ChartTooltip, Legend } from 'chart.js'
import { VueDatePicker } from '@vuepic/vue-datepicker';
import '@vuepic/vue-datepicker/dist/main.css';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, Title, ChartTooltip, Legend)

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
const form = useForm({ message: "", images: [], manual_timestamp: "" });
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
const activeSummaryTab = ref('yield');
const filters = ref({
    providers: [],
    categories: [],
    flags: [],
    search: '', // package name
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
const trendMetric = ref('avg_price');
const trendRawData = ref(null);
const trendLoading = ref(false);
const marketSummaryFilter = ref('all');

const getProviderColor = (providerName) => {
    const prov = providerName.toUpperCase();
    if (prov === '3' || prov.includes('TRI')) return '#D6005E';
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
        datasets.push({
            label: provider,
            backgroundColor: getProviderColor(provider),
            borderColor: getProviderColor(provider),
            data: data[trendMetric.value],
            tension: 0.3, // smooth curves
            borderWidth: 2,
            pointRadius: 3,
            pointHoverRadius: 6
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
            backgroundColor: 'rgba(17, 24, 39, 0.9)',
            titleColor: '#fff',
            bodyColor: '#e5e7eb',
            borderColor: 'rgba(75, 85, 99, 0.5)',
            borderWidth: 1,
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
                color: 'rgba(75, 85, 99, 0.2)',
                drawBorder: false
            },
            ticks: {
                color: '#9CA3AF'
            }
        },
        y: {
            grid: {
                color: 'rgba(75, 85, 99, 0.2)',
                drawBorder: false
            },
            ticks: {
                color: '#9CA3AF',
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
            params: { start_date, end_date, _: new Date().getTime() }
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
            const res = await axios.post(route('vlr.check'), {
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
            const comp = comparisonResults.value[activeSessionId.value]?.[pkg.id];
            const status = comp ? comp.status : null;
            if (!f.flags.includes(status)) return false;
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
    
    const now = new Date();
    const startOfToday = new Date(now.getFullYear(), now.getMonth(), now.getDate());
    
    const startOfWeek = new Date(startOfToday);
    const day = startOfWeek.getDay() || 7; 
    startOfWeek.setDate(startOfWeek.getDate() - day + 1);
    
    const startOfMonth = new Date(now.getFullYear(), now.getMonth(), 1);
    const startOfYear = new Date(now.getFullYear(), 0, 1);
    
    return pkgs.filter(pkg => {
        if (!pkg.image_timestamp) return false;
        
        const dateStr = pkg.image_timestamp.replace(' ', 'T');
        const pkgDate = new Date(dateStr);
        if (isNaN(pkgDate.getTime())) return false;
        
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
const yieldLandscapeHiddenProviders = ref([]);

const toggleYieldProvider = (prov) => {
    const idx = yieldLandscapeHiddenProviders.value.indexOf(prov);
    if (idx > -1) {
        yieldLandscapeHiddenProviders.value.splice(idx, 1);
    } else {
        yieldLandscapeHiddenProviders.value.push(prov);
    }
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
                    const yieldVal = Math.round(price / gb);
                    const idx = getEupBucketIndex(price);
                    
                    if (!yieldLandscapeHiddenProviders.value.includes(prov)) {
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
                backgroundColor: getProviderColor(prov) + 'CC',
                borderColor: getProviderColor(prov),
                borderWidth: 0.5,
                pointRadius: 4,
                pointHoverRadius: 6,
                hidden: yieldLandscapeHiddenProviders.value.includes(prov),
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
            borderColor: 'rgba(100, 116, 139, 0.4)',
            borderDash: [4, 4],
            borderWidth: 1.5,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: '#64748b',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 1,
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
                    const yieldVal = Math.round(price / gb);
                    const idx = days - 1;
                    
                    if (!yieldLandscapeHiddenProviders.value.includes(prov)) {
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
                backgroundColor: getProviderColor(prov) + 'CC',
                borderColor: getProviderColor(prov),
                borderWidth: 0.5,
                pointRadius: 4,
                pointHoverRadius: 6,
                hidden: yieldLandscapeHiddenProviders.value.includes(prov),
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
            borderColor: 'rgba(100, 116, 139, 0.4)',
            borderDash: [4, 4],
            borderWidth: 1.5,
            pointRadius: 3,
            pointHoverRadius: 5,
            pointBackgroundColor: '#64748b',
            pointBorderColor: '#ffffff',
            pointBorderWidth: 1,
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
        padding: { top: 0, right: 10, bottom: 0, left: 0 }
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
                color: '#64748b',
                font: { family: "'Inter', sans-serif", weight: '600', size: 11 }
            },
            afterBuildTicks: (scale) => {
                scale.ticks = labels.map((_, i) => ({ value: i }));
            },
            ticks: {
                callback: function(value) {
                    return labels[Math.round(value)] || '';
                },
                color: '#64748b',
                font: { family: "'Inter', sans-serif", weight: '500', size: 10 },
                padding: 8
            },
            grid: {
                color: '#f8fafc',
                drawBorder: false
            }
        },
        y: {
            beginAtZero: true,
            title: {
                display: true,
                text: 'Yield (Rp per GB)',
                color: '#64748b',
                font: { family: "'Inter', sans-serif", weight: '600', size: 11 }
            },
            ticks: {
                callback: function(value) {
                    return 'Rp ' + Number(value).toLocaleString('id-ID');
                },
                color: '#64748b',
                font: { family: "'Inter', sans-serif", weight: '500', size: 10 },
                padding: 8
            },
            grid: {
                color: '#f8fafc',
                borderDash: [4, 4],
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
            backgroundColor: 'rgba(30, 30, 32, 0.9)',
            titleColor: '#fff',
            bodyColor: '#ccc',
            borderColor: '#374151',
            borderWidth: 1
        }
    },
    scales: {
        y: {
            border: { display: true, color: '#4b5563', width: 1 },
            grid: { display: false },
            ticks: { color: '#9ca3af', font: { family: "'Inter', sans-serif", size: 10 } }
        },
        x: {
            border: { display: true, color: '#4b5563', width: 1 },
            grid: { display: false },
            ticks: { color: '#9ca3af', font: { family: "'Inter', sans-serif", size: 10 }, maxRotation: 45, minRotation: 0 }
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

const uploadDataForm = useForm({ data_file: null, manual_timestamp: "" });
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
        htmlContent = `<p class="text-gray-300 text-sm mb-4">Data hasil AI ini tidak memiliki pasangan yang cocok di dalam file CSV Ground Truth yang di-upload.</p>`;
    } else {
        const csv = csvResult.csv_row;
        htmlContent = `
        <div class="overflow-x-auto text-left mt-2 border border-slate-200 rounded-lg">
            <table class="w-full text-sm text-slate-700 divide-y divide-slate-200">
                <thead>
                    <tr class="bg-slate-50 text-slate-700 font-bold">
                        <th class="p-3">Atribut</th>
                        <th class="p-3">Hasil AI</th>
                        <th class="p-3">Data CSV</th>
                        <th class="p-3 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 bg-white font-medium">
                    <tr>
                        <td class="p-3 font-bold text-slate-800">Provider</td>
                        <td class="p-3">${pkg.provider}</td>
                        <td class="p-3">${csv.provider}</td>
                        <td class="p-3 text-center">✅</td>
                    </tr>
                    <tr>
                        <td class="p-3 font-bold text-slate-800">Kuota (GB)</td>
                        <td class="p-3">${pkg.gb}</td>
                        <td class="p-3">${csv.gb}</td>
                        <td class="p-3 text-center">✅</td>
                    </tr>
                    <tr>
                        <td class="p-3 font-bold text-slate-800">Harga (Rp)</td>
                        <td class="p-3">${Number(pkg.price).toLocaleString('id-ID')}</td>
                        <td class="p-3">${Number(csv.price).toLocaleString('id-ID')}</td>
                        <td class="p-3 text-center">${pkg.price == csv.price ? '✅' : '❌'}</td>
                    </tr>
                    <tr>
                        <td class="p-3 font-bold text-slate-800">Masa Aktif</td>
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

onMounted(() => {
    scrollToBottom();
    fetchKeys();
    pollStatus();
    fetchTrendData();
});

watch(trendDateRange, () => {
    fetchTrendData();
});
onUnmounted(() => {
    clearTimeout(pollTimer);
});
</script>

<template>
    <Head title="SmartScan AI" />
    <div
        class="h-screen flex bg-slate-50 text-slate-800 font-sans overflow-hidden"
    >
        <!-- SIDEBAR -->
        <div
            :class="sidebarOpen ? 'w-72' : 'w-0 opacity-0'"
            class="flex-shrink-0 bg-white flex flex-col transition-all duration-300 overflow-hidden border-r border-slate-200/80 shadow-sm"
        >
            <!-- Sidebar Header & New Chat -->
            <div
                class="p-4 border-b border-slate-200/80 flex items-center justify-between"
            >
                <h2
                    class="text-lg font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-indigo-600"
                >
                    SmartScan AI
                </h2>
                <button
                    @click="sidebarOpen = false"
                    class="p-1 hover:bg-slate-100 rounded-lg transition text-slate-500 hover:text-slate-800"
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
            <div class="p-3 border-b border-slate-200/80 space-y-4">
                <!-- Status Usage -->
                <div class="bg-slate-50/80 rounded-xl p-3 border border-slate-200 shadow-[0_2px_10px_rgba(0,0,0,0.02)]">
                    <div class="text-xs text-slate-500 mb-1">
                        Kapasitas Model
                    </div>
                    <div class="flex items-end justify-between">
                        <div>
                            <div class="text-xl font-bold text-blue-600">
                                {{
                                    Math.max(
                                        0,
                                        activeKeyCount * 1500 - totalUsage,
                                    )
                                }}
                            </div>
                            <div class="text-[10px] text-slate-400">
                                Permintaan tersisa
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-sm font-semibold text-slate-700">
                                {{ activeKeyCount }} Key
                            </div>
                            <div class="text-[10px] text-slate-400">Aktif</div>
                        </div>
                    </div>
                    <!-- Progress Bar -->
                    <div
                        class="w-full bg-slate-200 rounded-full h-1.5 mt-2 overflow-hidden"
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
                        class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs text-slate-800 placeholder-slate-400 focus:outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 transition"
                        required
                    />
                    <button
                        type="submit"
                        :disabled="keyLoading"
                        class="bg-slate-100 hover:bg-slate-200 border border-slate-300 text-slate-700 rounded-lg px-3 py-2 transition flex items-center justify-center shrink-0 disabled:opacity-50"
                    >
                        <svg
                            v-if="keyLoading"
                            class="w-4 h-4 animate-spin text-blue-600"
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
                            class="w-4 h-4 text-blue-600"
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
                    class="w-full flex items-center justify-center gap-2 px-4 py-2.5 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-lg transition border border-slate-300 shadow-sm hover:text-indigo-600 hover:border-indigo-200"
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
                    class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-2 mt-4 px-2"
                >
                    Terbaru
                </div>

                <div
                    v-for="list in sortedPricelists"
                    :key="list.id"
                    class="group flex items-center justify-between p-2 rounded-lg cursor-pointer transition text-sm"
                    :class="
                        activeSessionId === list.id
                            ? 'bg-indigo-50/80 border border-indigo-200/60 text-indigo-900 font-medium shadow-sm'
                            : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900'
                    "
                    @click="activeSessionId = list.id"
                >
                    <div class="flex items-center gap-3 overflow-hidden flex-1">
                        <svg
                            class="w-4 h-4 text-slate-400 shrink-0"
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
                            class="p-1 hover:bg-slate-200 rounded text-slate-400 hover:text-slate-700"
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
                            class="p-1 hover:bg-red-100 rounded text-slate-400 hover:text-red-600"
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
                class="p-4 border-t border-slate-200/80 text-xs text-slate-500 flex justify-between items-center bg-slate-50/50"
            >
                <span>API Keys: {{ activeKeyCount }} active</span>
                <span title="Total Usage">{{ totalUsage }} reqs</span>
            </div>
        </div>

        <!-- MAIN AREA -->
        <div class="flex-1 flex flex-col h-screen relative bg-[#f8fafc]">
            <!-- Topbar (Mobile Hamburger) -->
            <div
                class="h-14 flex items-center px-4 border-b border-slate-200/80 shrink-0 bg-white/80 backdrop-blur"
            >
                <button
                    v-if="!sidebarOpen"
                    @click="sidebarOpen = true"
                    class="p-2 hover:bg-slate-100 rounded-lg text-slate-600 transition"
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
                        class="text-sm text-slate-700 font-medium"
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
                                class="text-4xl font-extrabold mb-3 text-slate-900 tracking-tight"
                            >
                                SmartScan AI
                            </h1>
                            <p class="text-slate-500 text-lg">
                                Pilih modul dashboard dan unggah file untuk
                                dianalisis.
                            </p>
                        </div>

                        <!-- Tabs -->
                        <div class="flex justify-center mb-8">
                            <div class="bg-slate-200/70 p-1 rounded-xl inline-flex shadow-inner border border-slate-300/50">
                                <button @click="inputType = 'scan'" :class="inputType === 'scan' ? 'bg-indigo-600 text-white shadow-md' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50'" class="px-6 py-2.5 rounded-lg text-sm font-medium transition-all duration-300">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
                                        Scan Gambar (AI)
                                    </div>
                                </button>
                                <button @click="inputType = 'data'" :class="inputType === 'data' ? 'bg-green-600 text-white shadow-md' : 'text-slate-600 hover:text-slate-900 hover:bg-slate-100/50'" class="px-6 py-2.5 rounded-lg text-sm font-medium transition-all duration-300">
                                    <div class="flex items-center gap-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                        Input Data Manual
                                    </div>
                                </button>
                            </div>
                        </div>

                        <!-- Drag & Drop Zone (Input File Image) -->
                        <div v-show="inputType === 'scan'" class="w-full relative mb-12">
                            <label
                                class="w-full h-[320px] border-2 border-dashed border-slate-300 hover:border-indigo-500 hover:bg-indigo-50/20 transition-all rounded-3xl flex flex-col items-center justify-center cursor-pointer group shadow-[0_4px_20px_rgba(0,0,0,0.04)] relative overflow-hidden bg-white"
                                :class="{ 'cursor-default pointer-events-none': form.images.length > 0 }"
                            >
                                <input
                                    type="file"
                                    accept="image/*,.zip"
                                    multiple
                                    @change="(e) => { form.images = Array.from(e.target.files); }"
                                    class="hidden"
                                    :disabled="form.processing || form.images.length > 0"
                                />

                                <!-- Loading Overlay when Processing -->
                                <div
                                    v-if="form.processing"
                                    class="absolute inset-0 bg-white/90 backdrop-blur flex flex-col items-center justify-center z-20 transition-all"
                                >
                                    <div class="w-12 h-12 border-4 border-indigo-500 border-t-transparent rounded-full animate-spin mb-4"></div>
                                    <span class="font-semibold text-indigo-600 text-lg tracking-wide animate-pulse">Mengunggah & Membuat Sesi...</span>
                                </div>

                                <!-- Staging State (Files Selected) -->
                                <div v-if="form.images.length > 0" class="absolute inset-0 bg-slate-50 flex flex-col items-center justify-center p-8 z-10">
                                    <div class="w-16 h-16 bg-green-500/10 rounded-full flex items-center justify-center mb-4">
                                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    </div>
                                    <h3 class="text-xl font-bold text-slate-800 mb-2">{{ form.images.length }} File Terpilih</h3>
                                    
                                    <!-- Progress Bar -->
                                    <div class="w-full max-w-md mt-2 mb-1">
                                        <div class="flex justify-between text-sm text-slate-500 mb-2">
                                            <span>Ukuran: {{ formatBytes(totalFileSize) }}</span>
                                            <span>Batas: 100 MB</span>
                                        </div>
                                        <div class="w-full bg-slate-200 rounded-full h-3 overflow-hidden">
                                            <div 
                                                class="h-full rounded-full transition-all duration-500"
                                                :class="fileSizePercentage >= 100 ? 'bg-red-500' : 'bg-indigo-500'"
                                                :style="{ width: fileSizePercentage + '%' }"
                                            ></div>
                                        </div>
                                        <p v-if="fileSizePercentage >= 100" class="text-red-500 text-sm mt-2 text-center font-medium">Ukuran melebihi batas maksimal!</p>
                                    </div>

                                    <!-- Timestamp Override -->
                                    <div class="w-full max-w-xs mt-1 mb-2 flex flex-col pointer-events-auto">
                                        <label class="text-xs text-slate-500 mb-1">Atur Waktu (Opsional)</label>
                                        <input type="date" v-model="form.manual_timestamp" class="w-full bg-white border border-slate-300 rounded-lg text-sm text-slate-800 px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500 text-center shadow-sm">
                                    </div>

                                    <div class="flex gap-4 mt-3 pointer-events-auto">
                                        <button 
                                            @click.stop="form.images = []" 
                                            class="px-5 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                                        >
                                            Batal
                                        </button>
                                        <button 
                                            @click.stop="submit" 
                                            :disabled="fileSizePercentage >= 100"
                                            class="px-6 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-700 text-white shadow-lg shadow-indigo-500/25 transition-all font-semibold disabled:opacity-50 disabled:cursor-not-allowed"
                                        >
                                            Proses File
                                        </button>
                                    </div>
                                </div>

                                <!-- Default State (No Files) -->
                                <template v-else>
                                    <div class="w-20 h-20 bg-indigo-500/10 rounded-full flex items-center justify-center mb-5 group-hover:scale-110 group-hover:shadow-[0_0_30px_rgba(99,102,241,0.2)] transition-all group-hover:bg-indigo-500/20">
                                        <svg class="w-10 h-10 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"></path></svg>
                                    </div>
                                    <span class="text-xl font-bold text-slate-700 mb-2 group-hover:text-indigo-600 transition-colors">Pilih atau Tarik Gambar / ZIP ke Sini</span>
                                    <span class="text-sm text-slate-500 bg-slate-100 px-3 py-1 rounded-full border border-slate-200">Mendukung format JPG, PNG, dan ZIP</span>
                                </template>
                            </label>
                        </div>
                        
                        <!-- Input Data (CSV/Excel) -->
                        <div v-show="inputType === 'data'" class="w-full relative mb-12">
                            <label
                                class="w-full h-[320px] border-2 border-dashed border-slate-300 hover:border-green-500 hover:bg-green-50/20 transition-all rounded-3xl flex flex-col items-center justify-center cursor-pointer group shadow-[0_4px_20px_rgba(0,0,0,0.04)] relative overflow-hidden bg-white"
                                :class="{ 'cursor-default pointer-events-none': uploadDataForm.data_file }"
                            >
                                <input
                                    type="file"
                                    accept=".csv,.txt,.xlsx,.xls"
                                    @change="(e) => { uploadDataForm.data_file = e.target.files[0]; }"
                                    class="hidden"
                                    :disabled="uploadDataForm.processing || uploadDataForm.data_file"
                                />

                                <!-- Loading Overlay when Processing -->
                                <div
                                    v-if="uploadDataForm.processing"
                                    class="absolute inset-0 bg-white/90 backdrop-blur flex flex-col items-center justify-center z-20 transition-all"
                                >
                                    <div class="w-12 h-12 border-4 border-green-500 border-t-transparent rounded-full animate-spin mb-4"></div>
                                    <span class="font-semibold text-green-600 text-lg tracking-wide animate-pulse">Mengimpor Data...</span>
                                </div>

                                <!-- Staging State (File Selected) -->
                                <div v-if="uploadDataForm.data_file" class="absolute inset-0 bg-slate-50 flex flex-col items-center justify-center p-8 z-10">
                                    <div class="w-16 h-16 bg-green-500/10 rounded-full flex items-center justify-center mb-4">
                                        <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    </div>
                                    <h3 class="text-xl font-bold text-slate-800 mb-2">{{ uploadDataForm.data_file.name }}</h3>
                                    
                                    <!-- Timestamp Override -->
                                    <div class="w-full max-w-xs mt-2 mb-2 flex flex-col pointer-events-auto">
                                        <label class="text-xs text-slate-500 mb-1">Atur Waktu (Opsional)</label>
                                        <input type="date" v-model="uploadDataForm.manual_timestamp" class="w-full bg-white border border-slate-300 rounded-lg text-sm text-slate-800 px-3 py-2 focus:ring-green-500 focus:border-green-500 text-center shadow-sm">
                                    </div>

                                    <div class="flex gap-4 mt-3 pointer-events-auto">
                                        <button 
                                            @click.stop="uploadDataForm.data_file = null" 
                                            class="px-5 py-2.5 rounded-xl border border-slate-300 text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors font-medium"
                                        >
                                            Batal
                                        </button>
                                        <button 
                                            @click.stop="uploadData" 
                                            class="px-6 py-2.5 rounded-xl bg-green-600 hover:bg-green-700 text-white shadow-lg shadow-green-500/25 transition-all font-semibold"
                                        >
                                            Upload Data
                                        </button>
                                    </div>
                                </div>

                                <!-- Default State (No Files) -->
                                <template v-else>
                                    <div class="w-20 h-20 bg-green-500/10 rounded-full flex items-center justify-center mb-5 group-hover:scale-110 group-hover:shadow-[0_0_30px_rgba(34,197,94,0.2)] transition-all group-hover:bg-green-500/20">
                                        <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
                                    </div>
                                    <span class="text-xl font-bold text-slate-700 mb-2 group-hover:text-green-600 transition-colors">Pilih Data Excel / CSV</span>
                                    <span class="text-sm text-slate-500 bg-slate-100 px-3 py-1 rounded-full border border-slate-200">Mendukung format XLSX, XLS, CSV, TXT</span>
                                </template>
                            </label>
                        </div>
                    </div>

                    <!-- ACTIVE SESSION OUTPUTS -->
                    <template v-else>
                        <!-- Top Action Bar -->
                        <div
                            class="flex justify-between items-center bg-white p-4 rounded-2xl border border-slate-200 shadow-sm mb-6"
                        >
                            <h2 class="text-xl font-bold text-slate-800">
                                {{ activeSession.filename }}
                            </h2>
                            <div class="flex gap-3 items-center">
                                <div class="w-48">
                                    <VueDatePicker
                                        v-model="form.manual_timestamp"
                                        :enable-time-picker="true"
                                        placeholder="Atur Waktu (Opsional)"
                                    />
                                </div>
                                <label
                                    class="cursor-pointer px-4 py-2 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 border border-indigo-200 rounded-lg text-sm font-semibold transition flex items-center gap-2 shadow-sm"
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
                                
                                <label
                                    class="cursor-pointer px-4 py-2 bg-green-50 text-green-700 hover:bg-green-100 border border-green-200 rounded-lg text-sm font-semibold transition flex items-center gap-2 shadow-sm"
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
                                <div class="relative group z-50">
                                    <button
                                        v-if="
                                            activeSession.packages &&
                                            activeSession.packages.length > 0
                                        "
                                        class="px-4 py-2 bg-green-50 text-green-700 hover:bg-green-100 border border-green-200 rounded-lg text-sm font-semibold transition flex items-center gap-2 shadow-sm"
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
                                        <div class="w-40 bg-white border border-slate-200 rounded-lg shadow-xl overflow-hidden flex flex-col">
                                            <a :href="route('scanner.export', activeSession.id)" target="_blank" class="px-4 py-2 text-sm text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors flex items-center gap-2">
                                                <span>📊</span> Excel
                                            </a>
                                            <a :href="route('scanner.exportCsv', activeSession.id)" target="_blank" class="px-4 py-2 text-sm text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors flex items-center gap-2 border-t border-slate-100">
                                                <span>📝</span> CSV
                                            </a>
                                            <a :href="route('scanner.exportTxt', activeSession.id)" target="_blank" class="px-4 py-2 text-sm text-slate-700 hover:bg-slate-100 hover:text-slate-900 transition-colors flex items-center gap-2 border-t border-slate-100">
                                                <span>📄</span> Text
                                            </a>
                                        </div>
                                    </div>
                                </div>

                                <button
                                    @click="isChatOpen = true"
                                    class="px-4 py-2 bg-indigo-600 text-white hover:bg-indigo-700 rounded-lg text-sm font-semibold transition flex items-center gap-2 shadow-md shadow-indigo-500/10"
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
                            class="bg-white p-5 rounded-2xl border border-blue-200 flex flex-col gap-4 shadow-md mb-6"
                        >
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-4">
                                    <div
                                        class="w-6 h-6 border-2 border-blue-600 border-t-transparent rounded-full animate-spin"
                                    ></div>
                                    <span
                                        class="text-base font-semibold text-blue-700 animate-pulse"
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
                                    class="px-3 py-1.5 bg-red-50 text-red-700 hover:bg-red-100 border border-red-200 rounded-lg transition text-sm font-medium flex items-center gap-1.5"
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
                                <div class="w-full bg-slate-200 rounded-full h-2.5 overflow-hidden border border-slate-300/50">
                                    <div class="bg-gradient-to-r from-blue-500 to-indigo-500 h-2.5 rounded-full transition-all duration-700 ease-out relative overflow-hidden shadow-[0_0_12px_rgba(59,130,246,0.3)]"
                                         :style="{ width: extractionProgress + '%' }">
                                         <div class="absolute inset-0 bg-white/20 w-full h-full animate-[pulse_2s_infinite]"></div>
                                    </div>
                                </div>
                                <span class="text-sm font-bold text-blue-600 w-10 text-right">{{ extractionProgress }}%</span>
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
                                    class="px-4 py-2 bg-red-600 text-white hover:bg-red-700 rounded-lg transition flex items-center gap-2 font-semibold shadow-sm"
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
                            class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-md mb-6 relative"
                        >
                            <!-- Background accent -->
                            <div
                                class="absolute top-0 left-0 w-1 h-full bg-blue-600"
                            ></div>

                            <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                                <h3 class="font-bold text-slate-800 flex items-center gap-2">
                                    <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
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
                                                class="flex items-center gap-3 text-slate-700"
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
                                                class="flex items-center gap-3 text-slate-700"
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
                                                class="w-full text-left p-4 rounded-xl border transition-all duration-300"
                                                :class="activeSummaryTab === 'yield' ? 'bg-blue-50/90 border-blue-500 shadow-sm ring-1 ring-blue-500' : 'bg-slate-50/80 border-slate-200 hover:bg-slate-100 hover:border-slate-300'">
                                                <div class="text-xs text-slate-500 mb-1">Average Yield</div>
                                                <div class="font-bold text-slate-800 flex items-center gap-2" v-if="marketAverages.yield">
                                                    🥇 {{ marketAverages.yield.provider }} : {{ marketAverages.yield.value }}
                                                </div>
                                            </button>
                                            
                                            <!-- Average Price Tab -->
                                            <button @click="activeSummaryTab = 'price'" 
                                                class="w-full text-left p-4 rounded-xl border transition-all duration-300"
                                                :class="activeSummaryTab === 'price' ? 'bg-blue-50/90 border-blue-500 shadow-sm ring-1 ring-blue-500' : 'bg-slate-50/80 border-slate-200 hover:bg-slate-100 hover:border-slate-300'">
                                                <div class="text-xs text-slate-500 mb-1">Average Price</div>
                                                <div class="font-bold text-slate-800 flex items-center gap-2" v-if="marketAverages.price">
                                                    🥇 {{ marketAverages.price.provider }} : {{ marketAverages.price.value }}
                                                </div>
                                            </button>

                                            <!-- Average Data Quota Tab -->
                                            <button @click="activeSummaryTab = 'quota'" 
                                                class="w-full text-left p-4 rounded-xl border transition-all duration-300"
                                                :class="activeSummaryTab === 'quota' ? 'bg-blue-50/90 border-blue-500 shadow-sm ring-1 ring-blue-500' : 'bg-slate-50/80 border-slate-200 hover:bg-slate-100 hover:border-slate-300'">
                                                <div class="text-xs text-slate-500 mb-1">Average Data Quota</div>
                                                <div class="font-bold text-slate-800 flex items-center gap-2" v-if="marketAverages.quota">
                                                    🥇 {{ marketAverages.quota.provider }} : {{ marketAverages.quota.value }}
                                                </div>
                                            </button>

                                            <!-- Average Validity Tab -->
                                            <button @click="activeSummaryTab = 'validity'" 
                                                class="w-full text-left p-4 rounded-xl border transition-all duration-300"
                                                :class="activeSummaryTab === 'validity' ? 'bg-blue-50/90 border-blue-500 shadow-sm ring-1 ring-blue-500' : 'bg-slate-50/80 border-slate-200 hover:bg-slate-100 hover:border-slate-300'">
                                                <div class="text-xs text-slate-500 mb-1">Average Validity</div>
                                                <div class="font-bold text-slate-800 flex items-center gap-2" v-if="marketAverages.validity">
                                                    🥇 {{ marketAverages.validity.provider }} : {{ marketAverages.validity.value }}
                                                </div>
                                            </button>
                                        </div>

                                        <!-- Ranking Table -->
                                        <div class="bg-transparent mt-4 relative">
                                            <h4 class="text-sm font-semibold text-slate-700 uppercase tracking-wider mb-6 pb-2 border-b border-slate-200 flex items-center gap-2">
                                                Provider Ranking by 
                                                <span v-if="activeSummaryTab === 'yield'" class="text-blue-600 font-bold">Average Yield</span>
                                                <span v-else-if="activeSummaryTab === 'price'" class="text-blue-600 font-bold">Average Price</span>
                                                <span v-else-if="activeSummaryTab === 'quota'" class="text-blue-600 font-bold">Average Data Quota</span>
                                                <span v-else-if="activeSummaryTab === 'validity'" class="text-blue-600 font-bold">Average Validity</span>
                                            </h4>
                                            
                                            <div class="relative pb-8 pt-2" v-if="marketAverages[activeSummaryTab]">
                                                <div class="space-y-4 relative w-full pr-4">
                                                    <!-- Continuous Y-Axis Line -->
                                                    <div class="absolute top-0 -bottom-2 left-[8rem] w-[2px] bg-slate-300 z-0"></div>
                                                    <div v-for="(item, index) in marketAverages[activeSummaryTab].list" :key="item.provider" class="flex items-center group w-full">
                                                        <div class="w-8 font-bold text-sm text-center flex-shrink-0" :class="index === 0 ? 'text-yellow-500' : index === 1 ? 'text-slate-500' : index === 2 ? 'text-amber-600' : 'text-slate-400'">
                                                            #{{ index + 1 }}
                                                        </div>
                                                        <span class="w-24 pr-4 font-bold flex-shrink-0 text-sm text-right text-slate-700 relative cursor-help">
                                                            {{ item.provider }}
                                                            <!-- Tooltip Popup -->
                                                            <div class="absolute left-0 bottom-full mb-2 w-64 bg-slate-900 border border-slate-700 text-white rounded-lg p-3 shadow-2xl opacity-0 group-hover:opacity-100 pointer-events-none transition-all duration-300 z-50 transform translate-y-2 group-hover:translate-y-0 text-left">
                                                                <div class="text-[11px] text-slate-300 font-normal space-y-1.5">
                                                                    <div class="border-b border-slate-700 pb-1.5 mb-1.5 font-bold text-white uppercase text-xs">{{ item.provider }} Details</div>
                                                                    <div class="flex justify-between"><span>Jumlah Paket:</span> <span class="text-white font-medium">{{ item.count }} paket</span></div>
                                                                    
                                                                    <div v-if="activeSummaryTab === 'quota'" class="space-y-1.5">
                                                                        <div class="flex justify-between"><span>Total Quota (Semua Paket):</span> <span class="text-white font-medium">{{ formatNumber(item.sumGb, 1) }} GB</span></div>
                                                                        <div class="mt-2 pt-1 border-t border-slate-700/50">
                                                                            <div class="text-green-400 font-medium">Tertinggi: <span class="text-white">{{ formatNumber(item.details.quota.max.gb, 1) }} GB</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.quota.max.package_name }} <span class="text-slate-400">({{ item.details.quota.max.gb }}GB, {{ item.details.quota.max.days }} Hari)</span></div>
                                                                        </div>
                                                                        <div class="mt-1">
                                                                            <div class="text-red-400 font-medium">Terendah: <span class="text-white">{{ formatNumber(item.details.quota.min.gb, 1) }} GB</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.quota.min.package_name }} <span class="text-slate-400">({{ item.details.quota.min.gb }}GB, {{ item.details.quota.min.days }} Hari)</span></div>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    <div v-else-if="activeSummaryTab === 'price'" class="space-y-1.5">
                                                                        <div class="flex justify-between"><span>Total Harga Keseluruhan:</span> <span class="text-white font-medium">Rp{{ formatNumber(item.sumPrice, 0) }}</span></div>
                                                                        <div class="mt-2 pt-1 border-t border-slate-700/50">
                                                                            <div class="text-red-400 font-medium">Termahal: <span class="text-white">Rp{{ formatNumber(item.details.price.max.price, 0) }}</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.price.max.package_name }} <span class="text-slate-400">({{ item.details.price.max.gb }}GB, {{ item.details.price.max.days }} Hari)</span></div>
                                                                        </div>
                                                                        <div class="mt-1">
                                                                            <div class="text-green-400 font-medium">Termurah: <span class="text-white">Rp{{ formatNumber(item.details.price.min.price, 0) }}</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.price.min.package_name }} <span class="text-slate-400">({{ item.details.price.min.gb }}GB, {{ item.details.price.min.days }} Hari)</span></div>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    <div v-else-if="activeSummaryTab === 'yield'" class="space-y-1.5">
                                                                        <div class="mt-2 pt-1 border-t border-slate-700/50">
                                                                            <div class="text-green-400 font-medium">Yield Terbaik (Terendah): <span class="text-white">Rp{{ formatNumber(item.details.yield.min.yield_val, 0) }}/GB</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.yield.min.package_name }} <span class="text-slate-400">({{ item.details.yield.min.gb }}GB, {{ item.details.yield.min.days }} Hari)</span></div>
                                                                        </div>
                                                                        <div class="mt-1">
                                                                            <div class="text-red-400 font-medium">Yield Terburuk: <span class="text-white">Rp{{ formatNumber(item.details.yield.max.yield_val, 0) }}/GB</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.yield.max.package_name }} <span class="text-slate-400">({{ item.details.yield.max.gb }}GB, {{ item.details.yield.max.days }} Hari)</span></div>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                    <div v-else-if="activeSummaryTab === 'validity'" class="space-y-1.5">
                                                                        <div class="mt-2 pt-1 border-t border-slate-700/50">
                                                                            <div class="text-green-400 font-medium">Terlama: <span class="text-white">{{ item.details.validity.max.days }} Hari</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.validity.max.package_name }} <span class="text-slate-400">({{ item.details.validity.max.gb }}GB)</span></div>
                                                                        </div>
                                                                        <div class="mt-1">
                                                                            <div class="text-red-400 font-medium">Tersingkat: <span class="text-white">{{ item.details.validity.min.days }} Hari</span></div>
                                                                            <div class="text-slate-400 line-clamp-1">{{ item.details.validity.min.package_name }} <span class="text-slate-400">({{ item.details.validity.min.gb }}GB)</span></div>
                                                                        </div>
                                                                    </div>
                                                                    
                                                                </div>
                                                            </div>
                                                        </span>
                                                        
                                                        <!-- Flat Bar Chart -->
                                                        <div class="flex-1 flex items-center h-4 relative z-10 group-hover:opacity-90">
                                                            <div class="h-full transition-all duration-1000 rounded-r shadow-sm" :style="{ width: Math.max(item.percent, 1) + '%', backgroundColor: getProviderColor(item.provider) }"></div>
                                                            <span class="ml-3 font-bold text-slate-700 text-sm whitespace-nowrap">{{ item.value }}</span>
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
                                            <div v-else class="text-slate-400 italic text-sm py-4 text-center">Belum ada data untuk kategori ini.</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Competitiveness Summarize Section -->
                                <div class="mt-8 pt-8 border-t border-slate-200" v-if="activeSession.packages && activeSession.packages.length > 0">
                                <div class="mb-6 border-b border-slate-200 pb-2">
                                    <h4 class="text-sm font-bold text-slate-800 uppercase tracking-widest">Competitiveness Summarize</h4>
                                </div>
                            
                            <div class="grid grid-cols-1 lg:grid-cols-10 gap-6">
                                <!-- Competitive Heatmap (Far left, col-span-4) -->
                                <div class="lg:col-span-4 bg-transparent p-2 flex flex-col relative overflow-hidden">
                                    <h5 class="text-xs font-bold text-slate-700 uppercase tracking-wider mb-1 text-center">Competitive Heatmap</h5>
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
                                    <h5 class="text-xs font-bold text-slate-700 uppercase tracking-wider mb-1 text-center">Overall Competitiveness</h5>
                                    <p class="text-[10px] text-slate-400 text-center mb-4 italic">(By Average Package Efficiency)</p>
                                    <div class="flex-grow min-h-[200px] relative">
                                        <Bar v-if="overallCompetitivenessChartData.labels.length" :data="overallCompetitivenessChartData" :options="insightChartOptions" />
                                        <div v-else class="flex h-full items-center justify-center text-slate-400 text-sm">Tidak ada data</div>
                                    </div>
                                </div>
                                
                                <!-- Yield Distribution -->
                                <div class="lg:col-span-3 bg-transparent p-2 flex flex-col relative overflow-hidden">
                                    <h5 class="text-xs font-bold text-slate-700 uppercase tracking-wider mb-1 text-center">Yield Distribution</h5>
                                    <p class="text-[10px] text-slate-400 text-center mb-4 italic">(By Maximum Yield Rp/GB)</p>
                                    <div class="flex-grow min-h-[200px] relative">
                                        <Bar v-if="yieldDistributionChartData.labels.length" :data="yieldDistributionChartData" :options="insightChartOptions" />
                                        <div v-else class="flex h-full items-center justify-center text-slate-400 text-sm">Tidak ada data</div>
                                    </div>
                                </div>
                                
                            </div>
                        </div>

                        <!-- Competitive Yield Landscape Section -->
                        <div class="mt-12 pt-6 border-t border-slate-200">
                            
                            <!-- Interactive Legend & Median Indicator -->
                            <div class="flex flex-wrap items-center justify-center gap-6 mb-12">
                                <div class="flex flex-wrap items-center justify-center gap-1.5">
                                    <span class="text-[10px] font-bold text-slate-400 mr-2 uppercase tracking-widest">Provider:</span>
                                    <button
                                        v-for="prov in yieldLandscapeProviders"
                                        :key="prov"
                                        @click="toggleYieldProvider(prov)"
                                        class="flex items-center gap-1.5 px-2 py-1 rounded text-[11px] font-bold transition-all duration-200"
                                        :class="!yieldLandscapeHiddenProviders.includes(prov)
                                            ? 'text-slate-600 hover:bg-slate-50'
                                            : 'text-slate-300 opacity-50 hover:opacity-80'"
                                    >
                                        <span class="w-2 h-2 rounded-full" :style="{ backgroundColor: !yieldLandscapeHiddenProviders.includes(prov) ? getProviderColor(prov) : '#cbd5e1' }"></span>
                                        <span>{{ prov }}</span>
                                    </button>
                                </div>
                                <div class="w-px h-4 bg-slate-200 hidden sm:block"></div>
                                <div class="flex items-center gap-2 text-[11px] font-bold text-slate-500">
                                    <span class="inline-block w-4 border-t border-dashed border-slate-400 relative">
                                        <span class="absolute left-1/2 -top-[2.5px] -translate-x-1/2 w-1 h-1 bg-slate-500 transform rotate-45"></span>
                                    </span>
                                    <span>Median per bucket</span>
                                </div>
                            </div>

                            <!-- Chart 1: Monthly (Paket Bulanan) -->
                            <div class="mb-16">
                                <div class="mb-6 flex flex-col items-center text-center">
                                    <div class="flex items-center justify-center gap-2 mb-1">
                                        <span class="text-slate-400 font-bold text-[10px] tracking-widest uppercase">Monthly</span>
                                        <span class="text-slate-300 font-bold">·</span>
                                        <h4 class="text-[13px] font-bold text-slate-700">Paket Bulanan — Yield vs Slab EUP</h4>
                                    </div>
                                    <p class="text-[11px] text-slate-400 font-medium">Grouped by end-user price. Lower yield = better Rp/GB (more aggressive pricing).</p>
                                </div>
                                <div class="h-[500px] w-full bg-white relative">
                                    <Scatter
                                        v-if="monthlyYieldChartData.datasets.length > 0"
                                        :data="monthlyYieldChartData"
                                        :options="getYieldChartOptions(['0–25K', '25–50K', '50–75K', '75–100K', '100–125K', '125–150K', '150–200K', '200K+'])"
                                    />
                                    <div v-else class="flex h-full items-center justify-center text-slate-400 text-sm italic font-medium">Belum ada data paket bulanan untuk ditampilkan.</div>
                                </div>
                            </div>

                            <hr class="border-slate-100 mb-12 w-1/3 mx-auto" />

                            <!-- Chart 2: Sachet / Daily (Paket Harian) -->
                            <div>
                                <div class="mb-6 flex flex-col items-center text-center">
                                    <div class="flex items-center justify-center gap-2 mb-1">
                                        <span class="text-slate-400 font-bold text-[10px] tracking-widest uppercase">Sachet</span>
                                        <span class="text-slate-300 font-bold">·</span>
                                        <h4 class="text-[13px] font-bold text-slate-700">Paket Harian — Yield vs Validity</h4>
                                    </div>
                                    <p class="text-[11px] text-slate-400 font-medium">Grouped by validity days (1d–19d). Short validity usually carries higher Rp/GB.</p>
                                </div>
                                <div class="h-[500px] w-full bg-white relative">
                                    <Scatter
                                        v-if="sachetYieldChartData.datasets.length > 0"
                                        :data="sachetYieldChartData"
                                        :options="getYieldChartOptions(sachetYieldLabels)"
                                    />
                                    <div v-else class="flex h-full items-center justify-center text-slate-400 text-sm italic font-medium">Belum ada data paket harian/sachet untuk ditampilkan.</div>
                                </div>
                            </div>
                    </div>
                    </div>
                    </div>

                    <!-- AI Strategic Insight Section -->
                    <div class="mt-8 bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-md mb-8 p-6" v-if="activeSession.packages && activeSession.packages.length > 0">
                        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
                            <h4 class="text-sm font-bold text-slate-800 uppercase tracking-wider flex items-center gap-2">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                </svg>
                                AI Strategic Insight
                            </h4>
                            <button @click="generateAiInsight" :disabled="aiInsightLoading" class="mt-4 sm:mt-0 bg-indigo-600 hover:bg-indigo-700 text-white font-semibold text-xs py-2 px-4 rounded-md transition-colors disabled:opacity-50 flex items-center gap-2 shadow-sm">
                                <svg v-if="aiInsightLoading" class="animate-spin h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                                <span>{{ aiInsightLoading ? 'Analyzing Market...' : 'Generate AI Strategy' }}</span>
                            </button>
                        </div>
                        <div class="bg-slate-50 rounded-lg p-5 border border-slate-200/80 min-h-[150px] shadow-inner">
                            <div v-if="aiInsightLoading" class="flex flex-col items-center justify-center h-full text-slate-500 py-10">
                                <div class="w-12 h-12 rounded-full border-4 border-indigo-500/30 border-t-indigo-500 animate-spin mb-4"></div>
                                <p class="text-sm font-medium">Menganalisis persaingan provider dan merumuskan saran...</p>
                            </div>
                            <div v-else-if="aiInsightData" class="prose prose-sm max-w-none prose-indigo text-slate-700" v-html="parseMarkdown(aiInsightData)"></div>
                            <div v-else class="flex items-center justify-center h-full text-slate-400 py-10 text-sm italic">
                                Klik tombol di atas untuk mendapatkan insight pasar dan strategi dari AI.
                            </div>
                        </div>
                    </div>

                    <!-- Market Trend Section -->
                        <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-md mb-8 p-6">
                            <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center mb-6">
                                <h4 class="text-sm font-bold text-slate-800 uppercase tracking-wider">Market Trend</h4>
                                
                                <div class="flex flex-col sm:flex-row gap-3 mt-4 sm:mt-0">
                                    <select 
                                        v-model="trendMetric" 
                                        class="bg-white border border-slate-300 rounded-lg text-sm text-slate-800 py-1.5 px-3 focus:ring-blue-500 focus:border-blue-500 outline-none shadow-sm"
                                    >
                                        <option value="avg_price">Kenaikan/Penurunan Harga</option>
                                        <option value="avg_yield">Kenaikan/Penurunan Yield</option>
                                        <option value="count">Jumlah Penambahan Paket</option>
                                    </select>
                                    
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
                                    <p class="text-xs text-slate-400 mt-2">Debug: Range: {{ trendDateRange ? JSON.stringify(trendDateRange) : 'null' }}</p>
                                    <p class="text-xs text-slate-400">Labels length: {{ trendRawData?.labels?.length || 0 }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Extracted Data Table Widget -->
                        <div
                            v-if="
                                activeSession.packages &&
                                activeSession.packages.length > 0
                            "
                            class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-md mb-8"
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
                                        class="text-xs px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 rounded-md text-white transition border border-indigo-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm"
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
                                        class="text-xs px-3 py-1.5 bg-red-600 hover:bg-red-500 rounded-md text-white transition border border-red-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                                        Hapus Flag
                                    </button>
                                    <button
                                        @click="isFilterOpen = !isFilterOpen"
                                        class="text-xs px-3 py-1.5 bg-blue-600 hover:bg-blue-500 rounded-md text-white transition border border-blue-500 flex items-center justify-center gap-1 text-center font-medium shadow-sm"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path></svg>
                                        Filter
                                    </button>

                                    <button
                                        @click="toggleTable(activeSession.id)"
                                        class="text-xs px-3 py-1.5 bg-slate-100 hover:bg-slate-200 rounded-md text-slate-700 font-semibold transition border border-slate-300 flex items-center justify-center h-full text-center shadow-xs"
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
                                                <button @click="resetFilters" class="text-xs text-blue-600 font-bold hover:underline">Reset</button>
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
                                                        <input type="number" v-model="filters.priceMin" placeholder="Min" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                                                        <span class="text-slate-400">-</span>
                                                        <input type="number" v-model="filters.priceMax" placeholder="Max" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                                                    </div>
                                                </div>

                                                <!-- Masa Aktif Range -->
                                                <div>
                                                    <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Masa Aktif (Hari)</h5>
                                                    <div class="flex items-center gap-2">
                                                        <input type="number" v-model="filters.daysMin" placeholder="Min" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                                                        <span class="text-slate-400">-</span>
                                                        <input type="number" v-model="filters.daysMax" placeholder="Max" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                                                    </div>
                                                </div>

                                                <!-- Yield Range -->
                                                <div>
                                                    <h5 class="text-xs font-bold text-slate-600 mb-2 uppercase">Range Yield (Rp/GB)</h5>
                                                    <div class="flex items-center gap-2">
                                                        <input type="number" v-model="filters.yieldMin" placeholder="Min" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                                                        <span class="text-slate-400">-</span>
                                                        <input type="number" v-model="filters.yieldMax" placeholder="Max" class="w-full bg-white border border-slate-300 rounded px-2 py-1.5 text-xs text-slate-800 focus:border-blue-500 outline-none placeholder-slate-400 shadow-sm" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                <!-- LEFT: Source Images (Only show when editing for easier crosscheck) -->
                                <div
                                    v-if="isEditingTable[activeSession.id]"
                                    class="w-full xl:w-1/3 p-4 bg-slate-50 border-b xl:border-b-0 xl:border-r border-slate-200 max-h-[600px] overflow-y-auto custom-scrollbar"
                                >
                                    <h4
                                        class="text-xs text-slate-700 font-bold mb-4 uppercase tracking-wider sticky top-0 bg-slate-50 py-2 z-10"
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
                                                        class="rounded-lg border border-slate-200 hover:border-blue-500 transition cursor-zoom-in w-full object-contain bg-white shadow-sm"
                                                        @click="openImage(att)"
                                                        title="Klik untuk memperbesar"
                                                    />
                                                    <div
                                                        v-else
                                                        class="text-xs text-slate-700 font-medium p-2 bg-white rounded border border-slate-200 shadow-sm break-all flex items-center gap-2"
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
                                            class="text-xs text-slate-600 uppercase bg-slate-50 border-b border-slate-200 font-bold"
                                        >
                                            <tr>
                                                <th class="px-4 py-3">
                                                    TimeStamp
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
                                                class="border-b border-slate-100 transition-colors cursor-pointer hover:bg-slate-50/80"
                                                @click="openEditModal(pkg, activeSession.id)"
                                            >
                                                <td class="px-4 py-3 text-slate-600 text-xs truncate max-w-[150px]" :title="pkg.image_timestamp || pkg.created_at">
                                                    {{ pkg.image_timestamp || (pkg.created_at ? pkg.created_at.replace('T', ' ').substring(0, 19) : '-') }}
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
                                                    {{ pkg.package_name || '-' }}
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-right font-bold text-blue-600"
                                                >
                                                    {{ pkg.gb }} GB
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-right text-slate-600"
                                                >
                                                    {{ pkg.days }} Hari
                                                </td>
                                                <td
                                                    class="px-4 py-3 text-right text-slate-800 font-medium"
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
                                                class="border-b border-slate-200 bg-slate-50 hover:bg-slate-100/60"
                                            >
                                                <td class="px-2 py-2">
                                                    <div class="text-xs text-slate-500 truncate max-w-[100px]" :title="pkg.image_timestamp">{{ pkg.image_timestamp || '-' }}</div>
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
                                                            class="text-green-700 hover:text-green-800 p-1.5 bg-green-50 hover:bg-green-100 border border-green-200 rounded transition shadow-xs"
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
                                                            class="text-red-700 hover:text-red-800 p-1.5 bg-red-50 hover:bg-red-100 border border-red-200 rounded transition shadow-xs"
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
                                                            class="px-3 py-1.5 bg-white hover:bg-slate-100 border border-slate-300 rounded text-xs font-semibold text-slate-700 transition flex items-center gap-1 shadow-sm"
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
                                                            class="px-4 py-1.5 bg-blue-600 hover:bg-blue-500 rounded text-sm text-white font-semibold transition shadow-md flex items-center gap-2"
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
                                    class="bg-white p-6 rounded-2xl border border-slate-200 shadow-md flex flex-col h-full"
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
                                        <button @click="deleteChart(activeSession, msg)" class="text-slate-400 hover:text-red-600 transition-colors p-1 rounded-full hover:bg-slate-100" title="Hapus Grafik">
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
                        class="flex-1 overflow-y-auto p-4 space-y-4 custom-scrollbar bg-slate-50"
                    >
                        <div
                            v-if="
                                !activeSession ||
                                activeSession.chat_messages?.length === 0
                            "
                            class="h-full flex flex-col items-center justify-center text-center"
                        >
                            <div
                                class="w-16 h-16 bg-white border border-slate-200 rounded-full flex items-center justify-center mb-4 shadow-xs"
                            >
                                <svg
                                    class="w-8 h-8 text-slate-400"
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
                            <p class="text-sm font-bold text-slate-700">
                                Belum ada percakapan
                            </p>
                            <p class="text-xs text-slate-500 mt-1 max-w-[200px]">
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
                                class="p-2.5 text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg shrink-0 disabled:opacity-40 disabled:bg-slate-300 disabled:text-slate-500 transition shadow-xs"
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
                    class="pointer-events-auto w-14 h-14 bg-gradient-to-r from-blue-600 to-indigo-600 rounded-full flex items-center justify-center text-white shadow-[0_10px_25px_rgba(79,70,229,0.4)] hover:scale-105 hover:shadow-[0_10px_35px_rgba(79,70,229,0.6)] transition-all relative"
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

        <!-- VLR Checker Modal -->
        <div v-if="isVlrModalOpen" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div class="bg-white border border-slate-200 rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                    <h3 class="text-xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-600 to-indigo-600 flex items-center gap-2">
                        <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"></path>
                        </svg>
                        VLR Checker (Cek Umur Kartu)
                    </h3>
                    <button @click="isVlrModalOpen = false" class="text-slate-500 hover:text-slate-800 transition bg-slate-100 hover:bg-slate-200 p-2 rounded-full">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
                
                <div class="p-6 overflow-y-auto custom-scrollbar flex flex-col md:flex-row gap-6">
                    <!-- Input Section -->
                    <div class="w-full md:w-1/3 flex flex-col">
                        <label class="block text-sm font-bold text-slate-700 mb-2">Daftar Nomor Telepon (Satu per baris)</label>
                        <textarea 
                            v-model="vlrPhoneNumbers"
                            rows="10"
                            class="w-full bg-white border border-slate-300 text-slate-800 focus:border-blue-500 focus:ring-blue-500 rounded-xl shadow-inner mb-4 p-3 text-sm font-mono placeholder-slate-400 custom-scrollbar"
                            placeholder="Contoh:&#10;081234567890&#10;081987654321&#10;6285212341234"
                            :disabled="isVlrChecking"
                        ></textarea>
                        
                        <div v-if="vlrErrorMessage" class="text-red-600 text-sm mb-4 bg-red-50 p-3 rounded-lg border border-red-200 font-medium">
                            {{ vlrErrorMessage }}
                        </div>
                        
                        <button 
                            @click="checkVlrNumbers"
                            :disabled="isVlrChecking"
                            class="w-full px-4 py-3 bg-gradient-to-r from-blue-600 to-indigo-600 hover:from-blue-500 hover:to-indigo-500 text-white font-bold rounded-xl disabled:opacity-50 transition shadow-md flex items-center justify-center gap-2"
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
                        <label class="block text-sm font-bold text-slate-700 mb-2">Hasil Pengecekan</label>
                        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden flex-1 flex flex-col min-h-[300px] shadow-xs">
                            <div class="overflow-x-auto overflow-y-auto custom-scrollbar flex-1">
                                <table class="min-w-full divide-y divide-slate-200 text-sm text-left">
                                    <thead class="bg-slate-50 sticky top-0">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 font-bold text-slate-600">No. Telepon</th>
                                            <th scope="col" class="px-4 py-3 font-bold text-slate-600">Provider</th>
                                            <th scope="col" class="px-4 py-3 font-bold text-slate-600">Umur (Hari)</th>
                                            <th scope="col" class="px-4 py-3 font-bold text-slate-600">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-200">
                                        <tr v-if="vlrResults.length === 0">
                                            <td colspan="4" class="px-4 py-12 text-center text-slate-400 italic">
                                                Belum ada data. Masukkan nomor telepon dan klik cek.
                                            </td>
                                        </tr>
                                        <tr v-for="(res, index) in vlrResults" :key="index" class="hover:bg-slate-50/80 transition">
                                            <td class="px-4 py-3 font-mono font-semibold text-slate-800">
                                                {{ res.number }}
                                            </td>
                                            <td class="px-4 py-3 text-slate-600">
                                                {{ res.provider }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <span class="font-semibold" :class="{'text-slate-800': res.age !== '-', 'text-slate-400': res.age === '-'}">{{ res.age }}</span>
                                            </td>
                                            <td class="px-4 py-3">
                                                <span v-if="res.status === 'error'" class="px-2.5 py-1 inline-flex text-xs font-bold rounded-full bg-red-100 text-red-700 border border-red-200">
                                                    {{ res.type }}
                                                </span>
                                                <span v-else-if="res.age < 90" class="px-2.5 py-1 inline-flex text-xs font-bold rounded-full bg-green-100 text-green-700 border border-green-200">
                                                    {{ res.type || 'Babycare' }}
                                                </span>
                                                <span v-else class="px-2.5 py-1 inline-flex text-xs font-bold rounded-full bg-blue-100 text-blue-700 border border-blue-200">
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
        <!-- Edit Modal -->
        <div v-if="editModalPkg" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-900/60 backdrop-blur-sm p-4">
            <div class="bg-white border border-slate-200 rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto custom-scrollbar">
                <div class="sticky top-0 bg-slate-50 border-b border-slate-200 px-6 py-4 flex items-center justify-between z-10">
                    <h3 class="text-lg font-bold text-slate-800">Detail & Edit Data</h3>
                    <button @click="closeEditModal" class="text-slate-400 hover:text-slate-700 transition">
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
                                class="px-3 py-1.5 text-xs font-semibold bg-indigo-600 hover:bg-indigo-500 text-white rounded-md border border-indigo-500 shadow-sm transition flex items-center gap-1.5">
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
                    <button @click="closeEditModal" class="px-4 py-2 text-sm font-semibold text-slate-600 hover:text-slate-900 transition">Batal</button>
                    <button @click="saveRowEdit" :disabled="isSavingModal" class="px-4 py-2 text-sm font-semibold bg-blue-600 hover:bg-blue-500 rounded-md text-white transition shadow-md flex items-center gap-2">
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
