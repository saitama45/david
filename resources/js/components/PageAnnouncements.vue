<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Megaphone } from 'lucide-vue-next';

// Banners written in Sidebar Management > Announcements for the module of this page.
const page = usePage();
const announcements = computed(() => page.props.announcements || []);

const toneClass = {
    info: 'border-sky-200 bg-sky-50 text-sky-950',
    warning: 'border-amber-200 bg-amber-50 text-amber-950',
    success: 'border-green-200 bg-green-50 text-green-950',
};
</script>

<template>
    <div v-if="announcements.length" class="space-y-3">
        <section
            v-for="announcement in announcements"
            :key="announcement.id"
            class="flex gap-3 rounded-lg border p-4 text-sm"
            :class="toneClass[announcement.tone] || toneClass.info"
            role="status"
            aria-label="Announcement"
        >
            <Megaphone class="mt-0.5 size-5 shrink-0" />
            <div class="min-w-0">
                <p class="font-semibold">{{ announcement.title }}</p>
                <p class="mt-1 whitespace-pre-line">{{ announcement.message }}</p>
            </div>
        </section>
    </div>
</template>
