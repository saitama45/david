<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Search, BookOpen, ArrowRight, Layers } from 'lucide-vue-next';
import { formatDistanceToNow } from 'date-fns';

const props = defineProps({
    articles: Object,
    categories: Array,
    filters: Object
});

const search = ref(props.filters.search || '');

// Debounce search
let timeout;
watch(search, (value) => {
    clearTimeout(timeout);
    timeout = setTimeout(() => {
        router.get(
            route('knowledge-base.index'),
            { search: value, category: props.filters.category },
            { preserveState: true, replace: true, preserveScroll: true }
        );
    }, 300);
});

const filterByCategory = (category) => {
    router.get(
        route('knowledge-base.index'),
        { search: search.value, category: category },
        { preserveState: true, replace: true }
    );
};

const truncate = (text, length) => {
    if (!text) return '';
    return text.length > length ? text.substring(0, length) + '...' : text;
};
</script>

<template>
    <Layout heading="Knowledge Base">
        <!-- Hero Section with Search -->
        <div class="relative bg-white dark:bg-gray-900 rounded-xl overflow-hidden mb-8 border shadow-sm">
            <div class="absolute inset-0 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-gray-800 dark:to-gray-900 opacity-50"></div>
            <div class="relative max-w-3xl mx-auto py-12 px-4 sm:px-6 lg:px-8 text-center">
                <h2 class="text-3xl font-extrabold tracking-tight text-gray-900 dark:text-white sm:text-4xl">
                    How can we help you?
                </h2>
                <p class="mt-4 text-lg text-gray-500 dark:text-gray-400">
                    Search for answers, browse categories, or explore our latest articles.
                </p>
                <div class="mt-8 relative max-w-xl mx-auto">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <Search class="h-5 w-5 text-gray-400" />
                    </div>
                    <input
                        v-model="search"
                        type="text"
                        class="block w-full pl-10 pr-3 py-4 border border-gray-300 rounded-lg leading-5 bg-white dark:bg-gray-800 placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-indigo-500 focus:border-indigo-500 sm:text-sm shadow-sm transition-shadow duration-200 hover:shadow-md"
                        placeholder="Search for articles, topics, or keywords..."
                    />
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-4 gap-8">
            <!-- Sidebar: Categories -->
            <div class="lg:col-span-1 space-y-6">
                <div class="bg-white dark:bg-gray-800 rounded-lg border shadow-sm p-5">
                    <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4 flex items-center">
                        <Layers class="w-5 h-5 mr-2 text-indigo-500" />
                        Categories
                    </h3>
                    <nav class="space-y-2">
                        <button
                            @click="filterByCategory(null)"
                            class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors"
                            :class="!filters.category ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            All Categories
                        </button>
                        <button
                            v-for="category in categories"
                            :key="category"
                            @click="filterByCategory(category)"
                            class="w-full text-left px-3 py-2 rounded-md text-sm font-medium transition-colors"
                            :class="filters.category === category ? 'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/20 dark:text-indigo-300' : 'text-gray-600 hover:bg-gray-50 dark:text-gray-300 dark:hover:bg-gray-700'"
                        >
                            {{ category }}
                        </button>
                        <div v-if="categories.length === 0" class="text-sm text-gray-400 px-3">
                            No categories found.
                        </div>
                    </nav>
                </div>

                <!-- Quick Actions / Admin Link (Optional, for future use) -->
                <!-- 
                <div class="bg-white dark:bg-gray-800 rounded-lg border shadow-sm p-5">
                    <Link :href="route('knowledge-base.create')" class="w-full flex items-center justify-center px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                        Create Article
                    </Link>
                </div> 
                -->
            </div>

            <!-- Main Content: Articles Grid -->
            <div class="lg:col-span-3 space-y-6">
                <div class="flex justify-between items-end mb-2">
                    <h3 class="text-xl font-bold text-gray-900 dark:text-white">
                        {{ filters.category ? filters.category : (filters.search ? 'Search Results' : 'Latest Articles') }}
                    </h3>
                    <span class="text-sm text-gray-500">
                        Showing {{ articles.data.length }} of {{ articles.total }} articles
                    </span>
                </div>

                <div v-if="articles.data.length > 0" class="grid gap-6 md:grid-cols-2">
                    <div 
                        v-for="article in articles.data" 
                        :key="article.id" 
                        class="bg-white dark:bg-gray-800 rounded-lg border shadow-sm hover:shadow-md transition-all duration-200 flex flex-col h-full"
                    >
                        <div class="p-6 flex-1">
                            <div class="flex items-center justify-between mb-4">
                                <Badge v-if="article.category" variant="secondary" class="text-xs font-normal">
                                    {{ article.category }}
                                </Badge>
                                <span class="text-xs text-gray-500">
                                    {{ formatDistanceToNow(new Date(article.created_at), { addSuffix: true }) }}
                                </span>
                            </div>
                            <Link :href="route('knowledge-base.show', article.id)" class="block group">
                                <h4 class="text-lg font-bold text-gray-900 dark:text-white mb-2 group-hover:text-indigo-600 transition-colors">
                                    {{ article.title }}
                                </h4>
                                <div class="prose dark:prose-invert max-w-none text-gray-600 dark:text-gray-300 text-sm line-clamp-3" v-html="truncate(article.content, 150)"></div>
                            </Link>
                        </div>
                        <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/50 border-t rounded-b-lg flex items-center justify-between">
                            <div class="flex items-center space-x-2">
                                <div class="h-6 w-6 rounded-full bg-indigo-100 flex items-center justify-center text-indigo-700 text-xs font-bold">
                                    {{ article.author?.first_name?.charAt(0) || 'A' }}
                                </div>
                                <span class="text-xs text-gray-500 font-medium">
                                    {{ article.author?.first_name || 'Admin' }}
                                </span>
                            </div>
                            <Link :href="route('knowledge-base.show', article.id)" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 flex items-center">
                                Read Article <ArrowRight class="w-4 h-4 ml-1" />
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div v-else class="bg-white dark:bg-gray-800 rounded-lg border border-dashed p-12 text-center">
                    <BookOpen class="mx-auto h-12 w-12 text-gray-400" />
                    <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-white">No articles found</h3>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                        We couldn't find any articles matching your criteria.
                    </p>
                    <div class="mt-6">
                        <button 
                            @click="() => { search = ''; filterByCategory(null); }"
                            class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500"
                        >
                            Clear Filters
                        </button>
                    </div>
                </div>

                <!-- Pagination -->
                <div class="mt-8" v-if="articles.links.length > 3">
                     <Pagination :data="articles" />
                </div>
            </div>
        </div>
    </Layout>
</template>