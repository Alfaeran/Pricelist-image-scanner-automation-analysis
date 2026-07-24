<script setup>
import { ref, onMounted, watch } from 'vue';
import Chart from 'chart.js/auto';

const props = defineProps({
    config: {
        type: Object,
        required: true
    }
});

const canvasRef = ref(null);
let chartInstance = null;

const renderChart = () => {
    if (chartInstance) {
        chartInstance.destroy();
    }
    if (canvasRef.value && props.config) {
        // Clone config so Chart.js doesn't mutate the Vue reactive object and trigger infinite loop
        const configClone = JSON.parse(JSON.stringify(props.config));
        chartInstance = new Chart(canvasRef.value, configClone);
    }
};

onMounted(() => {
    renderChart();
});

watch(() => props.config, () => {
    renderChart();
}, { deep: true });
</script>

<template>
    <div class="w-full bg-white p-4 rounded-xl shadow-sm border border-gray-100">
        <canvas ref="canvasRef"></canvas>
    </div>
</template>
