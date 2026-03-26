<script setup>
import { ref } from 'vue';
import { Head, useForm, router, usePage } from '@inertiajs/vue3';
import PrimaryButton from '@/components/PrimaryButton.vue';
import InputLabel from '@/components/InputLabel.vue';
import InputError from '@/components/InputError.vue';
import { useToast } from 'primevue/usetoast';
import Toast from 'primevue/toast';
import { Download, UploadCloud, FileSpreadsheet, CheckCircle, Info, AlertTriangle } from 'lucide-vue-next';

const toast = useToast();
const page = usePage();

const currentYear = new Date().getFullYear();
const years = Array.from({ length: 11 }, (_, i) => currentYear - 5 + i);

const form = useForm({
    type: 'Sales',
    year: currentYear,
    file: null,
});

const fileInput = ref(null);
const dragging = ref(false);
const summaryData = ref(null);

// Watch for flash summary data from the server
import { watch } from 'vue';
watch(() => page.props.flash, (flash) => {
    console.log('Flash prop changed:', flash);
    if (flash?.summary_counts) {
        console.log('Summary data found in flash:', flash.summary_counts);
        summaryData.value = {
            counts: flash.summary_counts,
            messages: flash.summary_messages || []
        };
    } else {
        console.log('No summary_counts found in flash');
    }
}, { immediate: true });

const handleFileUpload = (e) => {
    form.file = e.target.files[0] || e.dataTransfer?.files[0];
};

const handleDrop = (e) => {
    dragging.value = false;
    if (e.dataTransfer?.files.length) {
        form.file = e.dataTransfer.files[0];
        if (fileInput.value) {
            const dataTransfer = new DataTransfer();
            dataTransfer.items.add(e.dataTransfer.files[0]);
            fileInput.value.files = dataTransfer.files;
        }
    }
};

const submit = () => {
    console.log('Submitting form with data:', {
        type: form.type,
        year: form.year,
        file: form.file ? form.file.name : null
    });
    summaryData.value = null; // reset summary on new upload
    form.post(route('sales-budget-uploader.upload'), {
        preserveScroll: true,
        onSuccess: () => {
            if (page.props.flash?.success) {
                toast.add({ severity: 'success', summary: 'Success', detail: page.props.flash.success, life: 3000 });
                
                // Store summary data for rendering
                if (page.props.flash?.summary_counts) {
                    summaryData.value = {
                        counts: page.props.flash.summary_counts,
                        messages: page.props.flash.summary_messages || []
                    };
                }

                form.reset('file');
                if (fileInput.value) {
                    fileInput.value.value = null;
                }
            }
        },
        onError: (errors) => {
             if (errors.error) {
                 toast.add({ severity: 'error', summary: 'Error', detail: errors.error, life: 5000 });
             }
        }
    });
};

const downloadTemplate = () => {
    window.location.href = route('sales-budget-uploader.download-template');
};
</script>

<template>
    <Head title="Sales/Budget Data Uploader" />

    <Layout heading="Sales/Budget Data Uploader">
        <Toast />
        <div class="py-8 max-w-5xl mx-auto space-y-8 sm:px-6 lg:px-8">
            
            <!-- Information & Download Section -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-xl border border-gray-100">
                <div class="p-8 sm:p-10 flex flex-col md:flex-row items-center justify-between gap-6">
                    <div class="flex-1">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="p-2 bg-indigo-50 rounded-lg text-indigo-600">
                                <FileSpreadsheet class="w-6 h-6" />
                            </div>
                            <h2 class="text-xl font-bold text-gray-900">Upload Target Sales & Budget</h2>
                        </div>
                        <p class="text-gray-600 leading-relaxed max-w-2xl">
                            Use this tool to upload target sales (Budget) or previous years' sales (Sales). 
                            This data is used solely for temporary reporting purposes on the Sales Dashboard.
                        </p>
                    </div>
                    <div class="flex-shrink-0 w-full md:w-auto">
                        <button 
                            type="button" 
                            @click="downloadTemplate" 
                            class="w-full md:w-auto inline-flex items-center justify-center gap-2 px-6 py-3 bg-white border-2 border-gray-200 text-gray-700 rounded-lg font-semibold hover:bg-gray-50 hover:border-gray-300 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-all shadow-sm"
                        >
                            <Download class="w-5 h-5" />
                            Download Template
                        </button>
                    </div>
                </div>
            </div>

            <!-- Upload Summary Results -->
            <div v-if="$page.props.flash.summary_counts" class="bg-white overflow-hidden shadow-sm sm:rounded-xl border border-gray-100 animate-in fade-in slide-in-from-bottom-4 duration-500">
                <div class="border-b border-gray-100 bg-gray-50/50 p-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                        <CheckCircle class="w-5 h-5 text-green-500" />
                        Import Summary
                    </h3>
                    <div class="flex flex-wrap gap-2 text-sm">
                        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-medium border border-green-200">
                            Inserted: {{ $page.props.flash.summary_counts.inserted }}
                        </span>
                        <span class="px-3 py-1 bg-blue-100 text-blue-700 rounded-full font-medium border border-blue-200">
                            Updated: {{ $page.props.flash.summary_counts.updated }}
                        </span>
                        <span class="px-3 py-1 bg-gray-100 text-gray-700 rounded-full font-medium border border-gray-200">
                            Skipped: {{ $page.props.flash.summary_counts.skipped }}
                        </span>
                    </div>
                </div>
                <div class="p-6">
                    <div v-if="!$page.props.flash.summary_messages || $page.props.flash.summary_messages.length === 0" class="text-gray-500 text-center py-4 italic">
                        No detailed operations were recorded.
                    </div>
                    <ul v-else class="space-y-3 max-h-96 overflow-y-auto pr-2 custom-scrollbar">
                        <li 
                            v-for="(msg, idx) in $page.props.flash.summary_messages" 
                            :key="idx"
                            class="p-4 rounded-xl border text-sm flex gap-3 shadow-sm transition-all hover:shadow-md"
                            :class="{
                                'bg-green-50 border-green-100 text-green-800': msg.type === 'inserted',
                                'bg-blue-50 border-blue-100 text-blue-800': msg.type === 'updated',
                                'bg-gray-50 border-gray-200 text-gray-600': msg.type === 'skipped'
                            }"
                        >
                            <div class="mt-0.5 flex-shrink-0">
                                <CheckCircle v-if="msg.type === 'inserted'" class="w-4 h-4 text-green-500" />
                                <Info v-if="msg.type === 'updated'" class="w-4 h-4 text-blue-500" />
                                <AlertTriangle v-if="msg.type === 'skipped'" class="w-4 h-4 text-gray-400" />
                            </div>
                            <div class="font-medium leading-relaxed">
                                <template v-if="msg.message.includes('values changed:')">
                                    <div>{{ msg.message.split('values changed:')[0] }}values changed:</div>
                                    <div class="ml-4 mt-1 space-y-1">
                                        <div v-for="(change, cIdx) in msg.message.split('values changed:')[1].split(',')" :key="cIdx">
                                            {{ change.trim() }}
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    {{ msg.message }}
                                </template>
                            </div>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Upload Form Section -->
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-xl border border-gray-100">
                <div class="border-b border-gray-100 bg-gray-50/50 p-6">
                    <h3 class="text-lg font-bold text-gray-900 flex items-center gap-2">
                        <UploadCloud class="w-5 h-5 text-gray-500" />
                        Data Import Process
                    </h3>
                </div>
                <div class="p-6 sm:p-8">
                    <form @submit.prevent="submit" class="space-y-8">
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- Step 1 -->
                            <div class="space-y-2 relative">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">1</span>
                                    <InputLabel for="type" value="Select Data Type" class="font-semibold text-gray-700" />
                                </div>
                                <select 
                                    id="type" 
                                    v-model="form.type" 
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm py-2.5 transition-colors"
                                    required
                                >
                                    <option value="Sales">Sales (Previous Years)</option>
                                    <option value="Budget">Budget (Target Sales)</option>
                                </select>
                                <InputError :message="form.errors.type" />
                            </div>

                            <!-- Step 2 -->
                            <div class="space-y-2 relative">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">2</span>
                                    <InputLabel for="year" value="Select Year" class="font-semibold text-gray-700" />
                                </div>
                                <select 
                                    id="year" 
                                    v-model="form.year" 
                                    class="w-full border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-lg shadow-sm py-2.5 transition-colors"
                                    required
                                >
                                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                                </select>
                                <InputError :message="form.errors.year" />
                            </div>
                        </div>

                        <!-- Step 3 -->
                        <div class="space-y-2">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="flex items-center justify-center w-6 h-6 rounded-full bg-indigo-100 text-indigo-700 text-xs font-bold">3</span>
                                <InputLabel for="file" value="Upload Filled Template" class="font-semibold text-gray-700" />
                            </div>
                            
                            <div 
                                class="mt-2 flex justify-center px-6 pt-5 pb-6 border-2 border-dashed rounded-xl transition-colors duration-200"
                                :class="[
                                    dragging ? 'border-indigo-500 bg-indigo-50' : 'border-gray-300 hover:border-indigo-400 bg-gray-50/50',
                                    form.file ? 'border-green-400 bg-green-50/30' : ''
                                ]"
                                @dragover.prevent="dragging = true"
                                @dragleave.prevent="dragging = false"
                                @drop.prevent="handleDrop"
                            >
                                <div class="space-y-2 text-center">
                                    <UploadCloud v-if="!form.file" class="mx-auto h-12 w-12 text-gray-400" />
                                    <FileSpreadsheet v-else class="mx-auto h-12 w-12 text-green-500" />
                                    
                                    <div class="flex text-sm text-gray-600 justify-center">
                                        <label
                                            for="file"
                                            class="relative cursor-pointer rounded-md font-semibold text-indigo-600 focus-within:outline-none hover:text-indigo-500"
                                        >
                                            <span>Upload a file</span>
                                            <input 
                                                id="file" 
                                                ref="fileInput"
                                                type="file" 
                                                class="sr-only" 
                                                accept=".csv, application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, application/vnd.ms-excel"
                                                @change="handleFileUpload" 
                                            />
                                        </label>
                                        <p class="pl-1">or drag and drop</p>
                                    </div>
                                    <p class="text-xs text-gray-500">
                                        Excel or CSV up to 10MB
                                    </p>
                                    <div v-if="form.file" class="text-sm font-medium text-green-600 mt-2 px-3 py-1 bg-green-100 rounded-full inline-block">
                                        Selected: {{ form.file.name }}
                                    </div>
                                </div>
                            </div>
                            <InputError :message="form.errors.file" />
                            
                            <div v-if="form.progress" class="w-full bg-gray-200 rounded-full h-2.5 mt-4">
                                <div class="bg-indigo-600 h-2.5 rounded-full transition-all duration-300" :style="`width: ${form.progress.percentage}%`"></div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-100 flex items-center justify-end">
                            <PrimaryButton 
                                :class="{ 'opacity-50 cursor-not-allowed': form.processing || !form.file }" 
                                :disabled="form.processing || !form.file"
                                class="px-8 py-3 text-base shadow-sm"
                            >
                                <span v-if="form.processing" class="flex items-center gap-2">
                                    <svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    Uploading...
                                </span>
                                <span v-else>Import Data</span>
                            </PrimaryButton>
                        </div>
                    </form>
                </div>
            </div>

        </div>
    </Layout>
</template>
