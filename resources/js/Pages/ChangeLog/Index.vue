<script setup>
import { computed, ref } from 'vue';
import { format, parseISO } from 'date-fns';
import { Search, ScrollText, Layers, ChevronDown, ListChecks, Scale, Users } from 'lucide-vue-next';
import { changeLog } from './entries';

const TYPES = {
    new: { label: 'New Release', class: 'bg-green-100 text-green-800 border-green-200' },
    improved: { label: 'Enhancements', class: 'bg-blue-100 text-blue-800 border-blue-200' },
    removed: { label: 'Removed', class: 'bg-gray-100 text-gray-700 border-gray-300' },
};

const entries = changeLog.map((entry, index) => ({ ...entry, id: index }));

const search = ref('');
const activeModule = ref(null);
const activeType = ref(null);
const expanded = ref(new Set());

const modules = computed(() => {
    const counts = {};
    entries.forEach((entry) => {
        counts[entry.module] = (counts[entry.module] || 0) + 1;
    });

    return Object.keys(counts).sort().map((name) => ({ name, count: counts[name] }));
});

const matchesSearch = (entry, term) =>
    [entry.title, entry.summary, entry.module, entry.affects, ...(entry.steps || []), ...(entry.rules || [])]
        .some((text) => String(text || '').toLowerCase().includes(term));

const filteredEntries = computed(() => {
    const term = search.value.trim().toLowerCase();

    return entries.filter((entry) =>
        (!activeModule.value || entry.module === activeModule.value) &&
        (!activeType.value || entry.type === activeType.value) &&
        (!term || matchesSearch(entry, term))
    );
});

// Entries are kept newest first, so the groups come out in that order too.
const groups = computed(() => {
    const byDate = new Map();
    filteredEntries.value.forEach((entry) => {
        if (!byDate.has(entry.date)) byDate.set(entry.date, []);
        byDate.get(entry.date).push(entry);
    });

    return [...byDate.entries()].map(([date, items]) => ({ date, items }));
});

const hasDetails = (entry) => Boolean(entry.steps?.length || entry.rules?.length);

const isExpanded = (entry) => expanded.value.has(entry.id);

const toggle = (entry) => {
    const next = new Set(expanded.value);
    next.has(entry.id) ? next.delete(entry.id) : next.add(entry.id);
    expanded.value = next;
};

const allExpanded = computed(() =>
    filteredEntries.value.length > 0 &&
    filteredEntries.value.filter(hasDetails).every((entry) => expanded.value.has(entry.id))
);

const toggleAll = () => {
    expanded.value = allExpanded.value
        ? new Set()
        : new Set(filteredEntries.value.filter(hasDetails).map((entry) => entry.id));
};

const clearFilters = () => {
    search.value = '';
    activeModule.value = null;
    activeType.value = null;
};

const formatDate = (date) => format(parseISO(date), 'MMMM d, yyyy');
const formatDay = (date) => format(parseISO(date), 'EEEE');
</script>

<template>
    <Layout heading="Change Log">
        <!-- Hero Section with Search -->
        <div class="relative bg-white dark:bg-gray-900 rounded-xl overflow-hidden mb-8 border shadow-sm">
            <div class="absolute inset-0 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-900 opacity-50"></div>
            <div class="relative max-w-3xl mx-auto py-10 px-4 sm:px-6 lg:px-8 text-center">
                <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                    What changed in the system
                </h2>
                <p class="mt-4 text-lg text-gray-500 dark:text-gray-400">
                    How the process works now and the business rules that apply.
                </p>
                <div class="mt-8 relative max-w-xl mx-auto">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <Search class="h-5 w-5 text-gray-400" />
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        class="block w-full pl-10 pr-3 py-4 border border-gray-300 rounded-lg leading-5 bg-white dark:bg-gray-800 placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm shadow-sm transition-shadow duration-200 hover:shadow-md"
                        placeholder="Search changes, modules, or rules..."
                    />
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Sidebar: Type and Module filters -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg border shadow-sm p-5">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                        <ScrollText class="w-5 h-5 mr-2 text-indigo-500" />
                        Type of change
                    </h3>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="(type, key) in TYPES"
                            :key="key"
                            @click="activeType = activeType === key ? null : key"
                            class="px-3 py-1 rounded-full text-xs font-semibold border transition-shadow"
                            :class="[type.class, activeType === key ? 'ring-2 ring-indigo-500 ring-offset-1' : 'opacity-80 hover:opacity-100']"
                        >
                            {{ type.label }}
                        </button>
                    </div>
                </div>

                <div class="bg-white dark:bg-gray-800 rounded-lg border shadow-sm p-5">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                        <Layers class="w-5 h-5 mr-2 text-indigo-500" />
                        Modules
                    </h3>
                    <nav class="space-y-1">
                        <button
                            @click="activeModule = null"
                            class="w-full flex items-center justify-between text-left px-3 py-2 rounded-md text-sm font-medium transition-colors"
                            :class="!activeModule ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            <span>All Modules</span>
                            <span class="text-xs opacity-70">{{ entries.length }}</span>
                        </button>
                        <button
                            v-for="module in modules"
                            :key="module.name"
                            @click="activeModule = module.name"
                            class="w-full flex items-center justify-between text-left px-3 py-2 rounded-md text-sm font-medium transition-colors"
                            :class="activeModule === module.name ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            <span>{{ module.name }}</span>
                            <span class="text-xs opacity-70">{{ module.count }}</span>
                        </button>
                    </nav>
                </div>
            </div>

            <!-- Main Content: changes grouped by date -->
            <div class="lg:col-span-3 space-y-6">
                <div class="flex flex-wrap justify-between items-end gap-2 mb-2">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                        {{ activeModule || 'All Changes' }}
                    </h3>
                    <div class="flex items-center gap-4">
                        <span class="text-sm text-gray-500">
                            Showing {{ filteredEntries.length }} of {{ entries.length }} changes
                        </span>
                        <button
                            v-if="filteredEntries.length > 0"
                            @click="toggleAll"
                            class="text-sm font-medium text-indigo-600 hover:text-indigo-800"
                        >
                            {{ allExpanded ? 'Collapse all' : 'Expand all' }}
                        </button>
                    </div>
                </div>

                <div v-for="group in groups" :key="group.date" class="space-y-4">
                    <div class="flex items-baseline gap-3 border-b pb-2">
                        <h4 class="text-lg font-bold text-gray-900 dark:text-white">{{ formatDate(group.date) }}</h4>
                        <span class="text-sm text-gray-500">{{ formatDay(group.date) }}</span>
                        <span class="ml-auto text-xs text-gray-500">{{ group.items.length }} change(s)</span>
                    </div>

                    <div
                        v-for="entry in group.items"
                        :key="entry.id"
                        class="bg-white dark:bg-gray-800 rounded-lg border shadow-sm"
                    >
                        <div class="p-5">
                            <div class="flex flex-wrap items-center gap-2 mb-3">
                                <span class="px-2 py-0.5 rounded text-xs font-bold border" :class="TYPES[entry.type].class">
                                    {{ TYPES[entry.type].label }}
                                </span>
                                <Badge variant="secondary" class="text-xs font-normal">{{ entry.module }}</Badge>
                            </div>
                            <h5 class="text-lg font-bold text-gray-900 dark:text-white">{{ entry.title }}</h5>
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300 leading-relaxed">{{ entry.summary }}</p>

                            <div v-if="isExpanded(entry)" class="mt-5 space-y-5">
                                <div v-if="entry.steps?.length">
                                    <h6 class="flex items-center text-sm font-semibold text-gray-900 dark:text-white mb-2">
                                        <ListChecks class="w-4 h-4 mr-2 text-indigo-500" />
                                        How it works now
                                    </h6>
                                    <ol class="list-decimal pl-6 space-y-1.5 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                        <li v-for="(step, index) in entry.steps" :key="index">{{ step }}</li>
                                    </ol>
                                </div>
                                <div v-if="entry.rules?.length">
                                    <h6 class="flex items-center text-sm font-semibold text-gray-900 dark:text-white mb-2">
                                        <Scale class="w-4 h-4 mr-2 text-indigo-500" />
                                        Business rules and conditions
                                    </h6>
                                    <ul class="list-disc pl-6 space-y-1.5 text-sm text-gray-700 dark:text-gray-300 leading-relaxed">
                                        <li v-for="(rule, index) in entry.rules" :key="index">{{ rule }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="px-5 py-3 bg-gray-50 dark:bg-gray-900/50 border-t rounded-b-lg flex flex-wrap items-center justify-between gap-2">
                            <span class="flex items-center text-xs text-gray-500">
                                <Users class="w-4 h-4 mr-1.5 shrink-0" />
                                {{ entry.affects }}
                            </span>
                            <button
                                v-if="hasDetails(entry)"
                                @click="toggle(entry)"
                                class="text-sm font-medium text-indigo-600 hover:text-indigo-800 flex items-center"
                            >
                                {{ isExpanded(entry) ? 'Hide details' : 'Process and rules' }}
                                <ChevronDown class="w-4 h-4 ml-1 transition-transform" :class="{ 'rotate-180': isExpanded(entry) }" />
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-if="filteredEntries.length === 0" class="bg-white dark:bg-gray-800 rounded-lg border border-dashed p-12 text-center">
                    <ScrollText class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No changes found</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        We couldn't find any changes matching your criteria.
                    </p>
                    <div class="mt-6">
                        <button
                            @click="clearFilters"
                            class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            Clear Filters
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Layout>
</template>
