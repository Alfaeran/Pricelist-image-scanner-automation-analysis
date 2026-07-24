<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import axios from 'axios';

const phoneNumbers = ref('');
const isChecking = ref(false);
const results = ref([]);
const errorMessage = ref('');

// Simple helper to guess provider based on prefix
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

const checkNumbers = async () => {
    if (!phoneNumbers.value.trim()) {
        errorMessage.value = 'Silakan masukkan nomor telepon.';
        return;
    }

    errorMessage.value = '';
    isChecking.value = true;
    
    // Split by new line, remove empties
    const list = phoneNumbers.value.split('\n').map(n => n.trim()).filter(n => n.length > 5);
    
    const newResults = [];
    
    for (const number of list) {
        const provider = guessProvider(number);
        
        try {
            const res = await axios.post('/vlr-checker/check', {
                phone_number: number,
                provider: provider
            });
            
            if (res.data.status === 'success') {
                newResults.push({
                    number: number,
                    provider: provider,
                    age: res.data.data.age_in_days,
                    type: res.data.data.product_type,
                    status: 'success'
                });
            } else {
                newResults.push({
                    number: number,
                    provider: provider,
                    age: '-',
                    type: 'Error: ' + (res.data.message || 'Unknown error'),
                    status: 'error'
                });
            }
        } catch (e) {
            newResults.push({
                number: number,
                provider: provider,
                age: '-',
                type: 'Gagal Terhubung',
                status: 'error'
            });
        }
    }
    
    results.value = newResults;
    isChecking.value = false;
};
</script>

<template>
    <Head title="VLR Checker" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">VLR Checker (Cek Umur Kartu)</h2>
        </template>

        <div class="py-12">
            <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
                <!-- Input Card -->
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Mengecek Status Babycare / Non-Babycare</h3>
                    <p class="text-sm text-gray-500 mb-4">
                        Masukkan nomor telepon (satu per baris). Sistem akan otomatis mendeteksi provider (Telkomsel, XL, dll) dan mengecek umurnya.
                    </p>
                    
                    <textarea 
                        v-model="phoneNumbers"
                        rows="5"
                        class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm mb-4"
                        placeholder="Contoh:&#10;081234567890&#10;081987654321"
                        :disabled="isChecking"
                    ></textarea>
                    
                    <div v-if="errorMessage" class="text-red-600 text-sm mb-4">
                        {{ errorMessage }}
                    </div>
                    
                    <button 
                        @click="checkNumbers"
                        :disabled="isChecking"
                        class="px-4 py-2 bg-indigo-600 text-white rounded hover:bg-indigo-700 disabled:opacity-50 transition"
                    >
                        {{ isChecking ? 'Sedang Mengecek...' : 'Cek Nomor' }}
                    </button>
                </div>

                <!-- Results Card -->
                <div v-if="results.length > 0" class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Hasil Pengecekan</h3>
                    
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">No. Telepon</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Provider</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Umur (Hari)</th>
                                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status Tipe</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr v-for="(res, index) in results" :key="index">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                                        {{ res.number }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ res.provider }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        {{ res.age }}
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span v-if="res.status === 'error'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800">
                                            {{ res.type }}
                                        </span>
                                        <span v-else-if="res.age < 90" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                            {{ res.type }}
                                        </span>
                                        <span v-else class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-blue-100 text-blue-800">
                                            {{ res.type }}
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
