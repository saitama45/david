<script setup>
import { computed, onBeforeUnmount, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Dialog from 'primevue/dialog';
import AutoComplete from 'primevue/autocomplete';
import { useToast } from 'primevue/usetoast';
import { Megaphone, Pencil, Plus } from 'lucide-vue-next';
import { MIN_SEARCH_CHARS } from '@/lib/searchRules';
import PageAnnouncements from '@/components/PageAnnouncements.vue';

// Sidebar Management > Announcements: a banner per module (sidebar page). No delete: an
// announcement is switched off (Active) or given an end date.
const props = defineProps({
    announcements: { type: Array, default: () => [] },
    // [{ value: menu_key, label, section }] — every sidebar page, labelled as the sidebar shows it.
    modules: { type: Array, default: () => [] },
    currentEntity: { type: Object, default: null },
    today: { type: String, required: true },
});

const toast = useToast();
const toneOptions = [
    { value: 'info', label: 'Information (blue)' },
    { value: 'warning', label: 'Warning (amber)' },
    { value: 'success', label: 'Good news (green)' },
];

const moduleLabel = (key) => {
    const found = props.modules.find((module) => module.value === key);
    return found ? found.label + (found.section ? ` (${found.section})` : '') : key;
};

const status = (announcement) => {
    if (!announcement.is_active) return { label: 'Off', class: 'bg-gray-100 text-gray-700' };
    if (announcement.starts_on && announcement.starts_on > props.today) return { label: 'Scheduled', class: 'bg-sky-100 text-sky-800' };
    if (announcement.ends_on && announcement.ends_on < props.today) return { label: 'Ended', class: 'bg-gray-100 text-gray-700' };
    return { label: 'Showing', class: 'bg-green-100 text-green-800' };
};

// --- Dialog ---
const dialog = ref({ open: false, editing: null });
const form = useForm({
    menu_key: null,
    entity_id: null,
    title: '',
    message: '',
    tone: 'info',
    starts_on: null,
    ends_on: null,
    is_active: true,
});

// The module picker filters the page list in memory, from MIN_SEARCH_CHARS characters
// (a shorter label is found when typed in full).
const pickedModule = ref(null);
const moduleSuggestions = ref([]);
const searchModules = ({ query }) => {
    const term = (query || '').trim().toLowerCase();
    moduleSuggestions.value = term.length < MIN_SEARCH_CHARS
        ? props.modules.filter((module) => module.label.toLowerCase() === term)
        : props.modules.filter((module) => `${module.label} ${module.section || ''}`.toLowerCase().includes(term)).slice(0, 8);
};
const onModulePicked = (value) => {
    form.menu_key = value && typeof value === 'object' ? value.value : null;
};

// Shown in: every entity, or one. Editing keeps an announcement's own entity on offer.
const entityChoices = computed(() => {
    const choices = [{ value: null, label: 'All entities' }];
    if (props.currentEntity) choices.push({ value: props.currentEntity.id, label: `Only ${props.currentEntity.name}` });
    const own = dialog.value.editing?.entity;
    if (own && own.id !== props.currentEntity?.id) choices.push({ value: own.id, label: `Only ${own.name}` });
    return choices;
});

const openDialog = (announcement = null) => {
    form.clearErrors();
    if (announcement) {
        form.defaults({
            menu_key: announcement.menu_key,
            entity_id: announcement.entity_id ?? null,
            title: announcement.title,
            message: announcement.message,
            tone: announcement.tone,
            starts_on: announcement.starts_on,
            ends_on: announcement.ends_on,
            is_active: announcement.is_active,
        });
    } else {
        form.defaults({ menu_key: null, entity_id: null, title: '', message: '', tone: 'info', starts_on: props.today, ends_on: null, is_active: true });
    }
    form.reset();
    pickedModule.value = announcement ? props.modules.find((module) => module.value === announcement.menu_key) ?? null : null;
    dialog.value = { open: true, editing: announcement };
};

// After saving, the banner is previewed here as users will see it, for 30 seconds.
const PREVIEW_SECONDS = 30;
const preview = ref(null);
let previewTimer = null;
const showPreview = (saved) => {
    clearTimeout(previewTimer);
    preview.value = { id: 'preview', title: saved.title, message: saved.message, tone: saved.tone, module: moduleLabel(saved.menu_key) };
    previewTimer = setTimeout(() => { preview.value = null; }, PREVIEW_SECONDS * 1000);
};
onBeforeUnmount(() => clearTimeout(previewTimer));

const save = () => {
    const saved = form.data();
    const options = {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            dialog.value.open = false;
            showPreview(saved);
            toast.add({ severity: 'success', summary: 'Saved', detail: 'Announcement saved.', life: 3000 });
        },
    };
    if (dialog.value.editing) {
        form.put(route('sidebar-management.announcements.update', dialog.value.editing.id), options);
    } else {
        form.post(route('sidebar-management.announcements.store'), options);
    }
};

const toggleActive = (announcement) => {
    useForm({
        menu_key: announcement.menu_key,
        entity_id: announcement.entity_id ?? null,
        title: announcement.title,
        message: announcement.message,
        tone: announcement.tone,
        starts_on: announcement.starts_on,
        ends_on: announcement.ends_on,
        is_active: !announcement.is_active,
    }).put(route('sidebar-management.announcements.update', announcement.id), {
        preserveScroll: true,
        onSuccess: () => toast.add({ severity: 'success', summary: 'Saved', detail: announcement.is_active ? 'Announcement switched off.' : 'Announcement switched on.', life: 3000 }),
    });
};

const formatDate = (value) => (value ? new Date(`${value}T00:00:00`).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : '');
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <p class="text-sm text-muted-foreground">
                A banner at the top of every page of a module, for every user who opens it. Switch it off, or give it an end date, when it is no longer needed.
            </p>
            <button
                type="button"
                class="inline-flex shrink-0 items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90"
                @click="openDialog()"
            >
                <Plus class="size-4" /> New Announcement
            </button>
        </div>

        <div v-if="preview" class="space-y-2 rounded-md border border-dashed border-primary/40 p-3">
            <p class="text-xs font-medium text-muted-foreground">
                Preview on {{ preview.module }}, as users see it. It closes by itself after {{ PREVIEW_SECONDS }} seconds.
            </p>
            <PageAnnouncements :items="[preview]" />
        </div>

        <div v-if="!announcements.length" class="rounded-md border border-dashed p-8 text-center text-sm text-muted-foreground">
            No announcements yet.
        </div>

        <div v-for="announcement in announcements" :key="announcement.id" class="rounded-md border border-border p-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="status(announcement).class">{{ status(announcement).label }}</span>
                        <span class="text-xs text-muted-foreground">{{ moduleLabel(announcement.menu_key) }} · {{ announcement.entity?.name ? `Only ${announcement.entity.name}` : 'All entities' }}</span>
                    </div>
                    <p class="flex items-center gap-2 font-medium"><Megaphone class="size-4 shrink-0" /> {{ announcement.title }}</p>
                    <p class="whitespace-pre-line text-sm text-muted-foreground">{{ announcement.message }}</p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="announcement.starts_on || announcement.ends_on">
                            {{ announcement.starts_on ? `From ${formatDate(announcement.starts_on)}` : '' }}
                            {{ announcement.ends_on ? `until ${formatDate(announcement.ends_on)}` : '(no end date)' }} ·
                        </template>
                        Updated {{ announcement.updated_at?.slice(0, 10) }}{{ announcement.updated_by ? ` by ${announcement.updated_by.first_name} ${announcement.updated_by.last_name}` : '' }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <button type="button" class="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm" @click="toggleActive(announcement)">
                        {{ announcement.is_active ? 'Switch Off' : 'Switch On' }}
                    </button>
                    <button type="button" class="inline-flex items-center gap-1 rounded-md border px-3 py-1.5 text-sm" title="Edit" @click="openDialog(announcement)">
                        <Pencil class="size-4" /> Edit
                    </button>
                </div>
            </div>
        </div>

        <Dialog v-model:visible="dialog.open" modal :header="dialog.editing ? 'Edit Announcement' : 'New Announcement'" :style="{ width: '40rem', maxWidth: '95vw' }">
            <form class="space-y-4" @submit.prevent="save">
                <div>
                    <label for="announcement_module" class="mb-1 block text-sm font-medium">Module <span class="text-red-500">*</span></label>
                    <AutoComplete
                        v-model="pickedModule"
                        input-id="announcement_module"
                        :suggestions="moduleSuggestions"
                        optionLabel="label"
                        :minLength="1"
                        forceSelection
                        showEmptyMessage
                        :emptySearchMessage="`Type at least ${MIN_SEARCH_CHARS} characters of a sidebar page, for example Mass Orders.`"
                        :placeholder="`Type at least ${MIN_SEARCH_CHARS} characters of a sidebar page`"
                        fluid
                        @complete="searchModules"
                        @update:modelValue="onModulePicked"
                    >
                        <template #option="{ option }">
                            <div class="flex flex-col">
                                <span>{{ option.label }}</span>
                                <span v-if="option.section" class="text-xs text-gray-500">{{ option.section }}</span>
                            </div>
                        </template>
                    </AutoComplete>
                    <p class="mt-1 text-xs text-muted-foreground">It shows on that page and on its other pages, such as an order's details.</p>
                    <p v-if="form.errors.menu_key" class="mt-1 text-sm text-red-600">{{ form.errors.menu_key }}</p>
                </div>

                <div>
                    <label for="announcement_title" class="mb-1 block text-sm font-medium">Title <span class="text-red-500">*</span></label>
                    <input id="announcement_title" v-model="form.title" type="text" maxlength="150" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                    <p v-if="form.errors.title" class="mt-1 text-sm text-red-600">{{ form.errors.title }}</p>
                </div>

                <div>
                    <label for="announcement_message" class="mb-1 block text-sm font-medium">Message <span class="text-red-500">*</span></label>
                    <textarea id="announcement_message" v-model="form.message" rows="6" maxlength="2000" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm"></textarea>
                    <p class="mt-1 text-xs text-muted-foreground">{{ form.message.length }} / 2000. Line breaks are kept.</p>
                    <p v-if="form.errors.message" class="mt-1 text-sm text-red-600">{{ form.errors.message }}</p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <fieldset>
                        <legend class="mb-1 text-sm font-medium">Shown in</legend>
                        <label v-for="choice in entityChoices" :key="String(choice.value)" class="flex items-center gap-2 text-sm">
                            <input v-model="form.entity_id" type="radio" :value="choice.value" /> {{ choice.label }}
                        </label>
                    </fieldset>
                    <fieldset>
                        <legend class="mb-1 text-sm font-medium">Color</legend>
                        <label v-for="tone in toneOptions" :key="tone.value" class="flex items-center gap-2 text-sm">
                            <input v-model="form.tone" type="radio" :value="tone.value" /> {{ tone.label }}
                        </label>
                    </fieldset>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label for="announcement_starts" class="mb-1 block text-sm font-medium">Show from</label>
                        <input id="announcement_starts" v-model="form.starts_on" type="date" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label for="announcement_ends" class="mb-1 block text-sm font-medium">Show until <span class="text-xs text-muted-foreground">(optional)</span></label>
                        <input id="announcement_ends" v-model="form.ends_on" type="date" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                        <p v-if="form.errors.ends_on" class="mt-1 text-sm text-red-600">{{ form.errors.ends_on }}</p>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input v-model="form.is_active" type="checkbox" /> Active
                </label>

                <div class="flex justify-end gap-2">
                    <button type="button" class="rounded-md border px-4 py-2 text-sm" @click="dialog.open = false">Cancel</button>
                    <button type="submit" :disabled="form.processing" class="rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground disabled:opacity-50">Save Announcement</button>
                </div>
            </form>
        </Dialog>
    </div>
</template>
