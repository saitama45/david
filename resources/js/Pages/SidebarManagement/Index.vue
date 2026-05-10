<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import draggable from 'vuedraggable';
import { useToast } from 'primevue/usetoast';
import { GripVertical, RotateCcw, Eye, EyeOff, ChevronRight, ChevronDown } from 'lucide-vue-next';

const props = defineProps({
    menuItems: {
        type: Array,
        required: true,
    },
});

const toast = useToast();

// ─── Tree builder ────────────────────────────────────────────────────────────
// Build a PLAIN OBJECT tree (not computed) so vuedraggable can mutate it.
const buildTree = (flat) => {
    const byKey = {};
    flat.forEach(i => {
        byKey[i.menu_key] = { ...i, children: [] };
    });

    const roots = [];
    flat.forEach(i => {
        const node = byKey[i.menu_key];
        if (i.parent_key && byKey[i.parent_key]) {
            byKey[i.parent_key].children.push(node);
        } else {
            roots.push(node);
        }
    });

    const sort = (nodes) => {
        nodes.sort((a, b) => a.sort_order - b.sort_order);
        nodes.forEach(n => sort(n.children));
        return nodes;
    };

    return sort(roots);
};

// treeData is a ref (mutable array) — vuedraggable writes directly to this.
const treeData = ref(buildTree(props.menuItems));

// ─── Collapse state ──────────────────────────────────────────────────────────
const expanded = ref({});
const toggleExpand = (key) => {
    expanded.value[key] = !expanded.value[key];
};
const isExpanded = (key) => expanded.value[key] !== false;

// ─── Flatten tree back to a list with updated sort_order / parent_key ────────
const flattenTree = (nodes, parentKey = null, result = []) => {
    nodes.forEach((node, idx) => {
        result.push({
            menu_key:     node.menu_key,
            parent_key:   parentKey,
            custom_label: node.custom_label || null,
            sort_order:   idx,
            is_active:    node.is_active,
        });
        if (node.children?.length) {
            flattenTree(node.children, node.menu_key, result);
        }
    });
    return result;
};

// Called when an item is dropped into a new parent group.
// Updates the node's parent_key to match where it was dropped.
const onDragEnd = (newParentKey, children) => {
    children.forEach((node) => {
        node.parent_key = newParentKey ?? null;
    });
};

// ─── Inline rename ───────────────────────────────────────────────────────────
const editing = ref(null);
const editValue = ref('');

const startEdit = (node) => {
    editing.value = node.menu_key;
    editValue.value = node.custom_label ?? node.default_label;
};

const commitEdit = (node) => {
    if (editing.value !== node.menu_key) return;
    const newLabel = editValue.value.trim();
    node.custom_label = (newLabel && newLabel !== node.default_label) ? newLabel : null;
    editing.value = null;
};

const cancelEdit = () => { editing.value = null; };

const resetLabel = (node) => { node.custom_label = null; };

const toggleActive = (node) => { node.is_active = !node.is_active; };

// ─── Save ────────────────────────────────────────────────────────────────────
const saving = ref(false);

const save = () => {
    saving.value = true;
    const payload = flattenTree(treeData.value);

    router.post(route('sidebar-management.bulk-update'), { items: payload }, {
        preserveScroll: true,
        onSuccess: () => {
            saving.value = false;
            toast.add({
                severity: 'success',
                summary: 'Saved',
                detail: 'Sidebar settings updated successfully.',
                life: 3000,
            });
        },
        onError: () => {
            saving.value = false;
            toast.add({
                severity: 'error',
                summary: 'Error',
                detail: 'Failed to save sidebar settings. Please try again.',
                life: 4000,
            });
        },
    });
};

// ─── Styles ──────────────────────────────────────────────────────────────────
const levelBg = ['bg-muted/40 border border-border rounded-md', 'bg-muted/20 border border-border/50 rounded', 'bg-background border border-border/30 rounded'];
</script>

<template>
    <Layout heading="Sidebar Management">
        <div class="max-w-4xl mx-auto space-y-4">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <p class="text-sm text-muted-foreground">
                    Drag to reorder · Click label to rename · Toggle to show/hide. Changes apply to all users after saving.
                </p>
                <button
                    @click="save"
                    :disabled="saving"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-primary text-primary-foreground rounded-md text-sm font-medium hover:bg-primary/90 disabled:opacity-50"
                >
                    {{ saving ? 'Saving...' : 'Save Changes' }}
                </button>
            </div>

            <!-- Level 0: top-level parents -->
            <draggable
                v-model="treeData"
                item-key="menu_key"
                handle=".drag-handle"
                :group="{ name: 'menu', pull: true, put: true }"
                @end="onDragEnd(null, treeData)"
                class="space-y-1"
            >
                <template #item="{ element: parent }">
                    <div :class="['p-1', levelBg[0]]">
                        <!-- Parent row -->
                        <div class="flex items-center gap-2 px-2 py-2">
                            <GripVertical class="drag-handle h-4 w-4 text-muted-foreground cursor-grab shrink-0" />

                            <button v-if="parent.children.length" @click="toggleExpand(parent.menu_key)" class="shrink-0">
                                <ChevronDown v-if="isExpanded(parent.menu_key)" class="h-4 w-4 text-muted-foreground" />
                                <ChevronRight v-else class="h-4 w-4 text-muted-foreground" />
                            </button>
                            <span v-else class="w-4 shrink-0" />

                            <div class="flex-1 min-w-0">
                                <input
                                    v-if="editing === parent.menu_key"
                                    v-model="editValue"
                                    @blur="commitEdit(parent)"
                                    @keyup.enter="commitEdit(parent)"
                                    @keyup.escape="cancelEdit"
                                    class="w-full border rounded px-2 py-0.5 text-sm bg-background"
                                    autofocus
                                />
                                <span
                                    v-else
                                    @click="startEdit(parent)"
                                    :class="['cursor-pointer hover:underline font-semibold text-sm', !parent.is_active ? 'line-through opacity-50' : '']"
                                >
                                    {{ parent.custom_label ?? parent.default_label }}
                                    <span v-if="parent.custom_label" class="text-xs text-blue-500 font-normal ml-1">(renamed)</span>
                                </span>
                            </div>

                            <button v-if="parent.custom_label" @click="resetLabel(parent)" title="Reset to default" class="shrink-0 text-muted-foreground hover:text-foreground">
                                <RotateCcw class="h-3 w-3" />
                            </button>

                            <button
                                @click="toggleActive(parent)"
                                :class="['shrink-0 flex items-center gap-1 text-xs px-2 py-0.5 rounded-full border transition-colors', parent.is_active ? 'bg-green-100 border-green-400 text-green-700 hover:bg-green-200' : 'bg-gray-100 border-gray-400 text-gray-500 hover:bg-gray-200']"
                            >
                                <Eye v-if="parent.is_active" class="h-3 w-3" />
                                <EyeOff v-else class="h-3 w-3" />
                                {{ parent.is_active ? 'Active' : 'Inactive' }}
                            </button>
                        </div>

                        <!-- Level 1: submenus -->
                        <draggable
                            v-if="parent.children.length && isExpanded(parent.menu_key)"
                            v-model="parent.children"
                            item-key="menu_key"
                            handle=".drag-handle"
                            :group="{ name: 'menu', pull: true, put: true }"
                            @end="onDragEnd(parent.menu_key, parent.children)"
                            class="space-y-0.5 mt-1 ml-2"
                        >
                            <template #item="{ element: submenu }">
                                <div :class="['p-0.5', levelBg[1]]">
                                    <div class="flex items-center gap-2 px-2 py-1.5">
                                        <GripVertical class="drag-handle h-4 w-4 text-muted-foreground cursor-grab shrink-0" />

                                        <button v-if="submenu.children.length" @click="toggleExpand(submenu.menu_key)" class="shrink-0">
                                            <ChevronDown v-if="isExpanded(submenu.menu_key)" class="h-3 w-3 text-muted-foreground" />
                                            <ChevronRight v-else class="h-3 w-3 text-muted-foreground" />
                                        </button>
                                        <span v-else class="w-3 shrink-0" />

                                        <div class="flex-1 min-w-0">
                                            <input
                                                v-if="editing === submenu.menu_key"
                                                v-model="editValue"
                                                @blur="commitEdit(submenu)"
                                                @keyup.enter="commitEdit(submenu)"
                                                @keyup.escape="cancelEdit"
                                                class="w-full border rounded px-2 py-0.5 text-xs bg-background"
                                                autofocus
                                            />
                                            <span
                                                v-else
                                                @click="startEdit(submenu)"
                                                :class="['cursor-pointer hover:underline text-sm text-muted-foreground', !submenu.is_active ? 'line-through opacity-50' : '']"
                                            >
                                                {{ submenu.custom_label ?? submenu.default_label }}
                                                <span v-if="submenu.custom_label" class="text-xs text-blue-500 ml-1">(renamed)</span>
                                            </span>
                                        </div>

                                        <button v-if="submenu.custom_label" @click="resetLabel(submenu)" title="Reset to default" class="shrink-0 text-muted-foreground hover:text-foreground">
                                            <RotateCcw class="h-3 w-3" />
                                        </button>

                                        <button
                                            @click="toggleActive(submenu)"
                                            :class="['shrink-0 flex items-center gap-1 text-xs px-2 py-0.5 rounded-full border transition-colors', submenu.is_active ? 'bg-green-100 border-green-400 text-green-700 hover:bg-green-200' : 'bg-gray-100 border-gray-400 text-gray-500 hover:bg-gray-200']"
                                        >
                                            <Eye v-if="submenu.is_active" class="h-3 w-3" />
                                            <EyeOff v-else class="h-3 w-3" />
                                            {{ submenu.is_active ? 'Active' : 'Inactive' }}
                                        </button>
                                    </div>

                                    <!-- Level 2: child items -->
                                    <draggable
                                        v-if="submenu.children.length && isExpanded(submenu.menu_key)"
                                        v-model="submenu.children"
                                        item-key="menu_key"
                                        handle=".drag-handle"
                                        :group="{ name: 'menu', pull: true, put: true }"
                                        @end="onDragEnd(submenu.menu_key, submenu.children)"
                                        class="space-y-0.5 mt-0.5 ml-2"
                                    >
                                        <template #item="{ element: child }">
                                            <div :class="['flex items-center gap-2 px-2 py-1', levelBg[2]]">
                                                <GripVertical class="drag-handle h-3 w-3 text-muted-foreground cursor-grab shrink-0" />
                                                <span class="w-3 shrink-0" />

                                                <div class="flex-1 min-w-0">
                                                    <input
                                                        v-if="editing === child.menu_key"
                                                        v-model="editValue"
                                                        @blur="commitEdit(child)"
                                                        @keyup.enter="commitEdit(child)"
                                                        @keyup.escape="cancelEdit"
                                                        class="w-full border rounded px-2 py-0.5 text-xs bg-background"
                                                        autofocus
                                                    />
                                                    <span
                                                        v-else
                                                        @click="startEdit(child)"
                                                        :class="['cursor-pointer hover:underline text-xs text-muted-foreground', !child.is_active ? 'line-through opacity-50' : '']"
                                                    >
                                                        {{ child.custom_label ?? child.default_label }}
                                                        <span v-if="child.custom_label" class="text-xs text-blue-500 ml-1">(renamed)</span>
                                                    </span>
                                                </div>

                                                <button v-if="child.custom_label" @click="resetLabel(child)" title="Reset to default" class="shrink-0 text-muted-foreground hover:text-foreground">
                                                    <RotateCcw class="h-3 w-3" />
                                                </button>

                                                <button
                                                    @click="toggleActive(child)"
                                                    :class="['shrink-0 flex items-center gap-1 text-xs px-2 py-0.5 rounded-full border transition-colors', child.is_active ? 'bg-green-100 border-green-400 text-green-700 hover:bg-green-200' : 'bg-gray-100 border-gray-400 text-gray-500 hover:bg-gray-200']"
                                                >
                                                    <Eye v-if="child.is_active" class="h-3 w-3" />
                                                    <EyeOff v-else class="h-3 w-3" />
                                                    {{ child.is_active ? 'Active' : 'Inactive' }}
                                                </button>
                                            </div>
                                        </template>
                                    </draggable>
                                </div>
                            </template>
                        </draggable>
                    </div>
                </template>
            </draggable>

            <!-- Save footer -->
            <div class="flex justify-end pt-2 border-t">
                <button
                    @click="save"
                    :disabled="saving"
                    class="inline-flex items-center gap-2 px-6 py-2 bg-primary text-primary-foreground rounded-md text-sm font-medium hover:bg-primary/90 disabled:opacity-50"
                >
                    {{ saving ? 'Saving...' : 'Save Changes' }}
                </button>
            </div>
        </div>
    </Layout>
</template>
