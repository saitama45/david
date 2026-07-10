<script setup>
import { computed } from "vue";
import { router, usePage } from "@inertiajs/vue3";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
    Building2,
    ChevronsUpDown,
    Check,
    ExternalLink,
    Server,
} from "lucide-vue-next";

const page = usePage();

const entities = computed(() => page.props.auth?.entities ?? []);
const activeEntityId = computed(() => page.props.auth?.activeEntity ?? null);
const activeEntity = computed(
    () => entities.value.find((e) => e.id === activeEntityId.value) ?? null
);

const switchEntity = (entity) => {
    if (entity.id === activeEntityId.value) return;
    router.post(
        "/entity/switch",
        { entity_id: entity.id },
        { preserveScroll: true }
    );
};
</script>

<template>
    <div v-if="entities.length" class="border-t p-3">
        <DropdownMenu>
            <DropdownMenuTrigger
                class="flex w-full flex-nowrap items-center justify-between gap-2 rounded-md border bg-background px-3 py-2 text-left text-sm hover:bg-muted/60 focus:outline-none focus:ring-1 focus:ring-ring"
            >
                <span class="flex flex-1 items-center gap-2 min-w-0 overflow-hidden">
                    <img
                        v-if="activeEntity && activeEntity.logo_url"
                        :src="activeEntity.logo_url"
                        :alt="activeEntity.name"
                        class="h-6 w-6 shrink-0 rounded object-contain bg-white border"
                    />
                    <Building2 v-else class="h-4 w-4 shrink-0 text-muted-foreground" />
                    <span class="flex flex-col min-w-0 overflow-hidden">
                        <span class="truncate font-medium leading-tight">
                            {{ activeEntity ? activeEntity.name : "Select entity" }}
                        </span>
                        <span
                            v-if="activeEntity"
                            class="truncate text-xs text-muted-foreground leading-tight"
                        >
                            {{ activeEntity.code }}
                        </span>
                    </span>
                </span>
                <ChevronsUpDown class="h-4 w-4 shrink-0 text-muted-foreground" />
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-56">
                <DropdownMenuLabel>Switch entity</DropdownMenuLabel>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    v-for="entity in entities"
                    :key="entity.id"
                    class="cursor-pointer flex items-center justify-between"
                    @click="switchEntity(entity)"
                >
                    <span class="flex items-center gap-2 min-w-0">
                        <img
                            v-if="entity.logo_url"
                            :src="entity.logo_url"
                            :alt="entity.name"
                            class="h-5 w-5 shrink-0 rounded object-contain bg-white border"
                        />
                        <span class="flex flex-col min-w-0">
                            <span class="truncate">{{ entity.name }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{ entity.code }}</span>
                        </span>
                    </span>
                    <Check
                        v-if="entity.id === activeEntityId"
                        class="h-4 w-4 text-primary"
                    />
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem as-child>
                    <a
                        href="https://davidtest.runasp.net/"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="cursor-pointer"
                    >
                        <Server class="text-muted-foreground" />
                        <span class="flex flex-1 flex-col min-w-0">
                            <span class="font-medium">Test Environment</span>
                            <span class="text-xs text-muted-foreground">
                                Open in new tab
                            </span>
                        </span>
                        <ExternalLink class="text-muted-foreground" />
                    </a>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
