<script setup>
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from "@/components/ui/collapsible";

import NavLink from "./NavLink.vue"; // Assuming NavLink.vue is in the same directory
import { usePage } from "@inertiajs/vue3";
import { ref, computed, watchEffect } from "vue"; // Import 'watchEffect'

import {
    FileCog,
    Bell,
    CircleUser,
    Home,
    Menu,
    ShoppingCart,
    SquareChartGantt,
    Folders,
    FileCheck,
    PackageSearch,
    ScrollText,
    LayoutList,
    Store,
    Container,
    MonitorCog,
    UsersRound,
    CalendarCheck2,
    ShoppingBasket,
    ClipboardList,
    ClipboardCheck,
    ArrowLeftRight,
    ChartColumnBig,
    FolderKanban,
    Scroll,
    List,
    IceCreamCone,
    FishSymbol,
    Vegan,
    ScanBarcode,
    FolderDot,
    FileSliders,
    FileUp,
    AppWindowMac,
    Warehouse,
    TextSelect,
    Truck,
    Trash2,
    ChevronDown,
    ChevronRight,
    Calculator,
} from "lucide-vue-next";

const auth = computed(() => usePage().props.auth);
const permissions = computed(() => auth.value.permissions || []);
const isAdmin = computed(() => auth.value.is_admin);

// Helper function to check if the current user has a specific permission.
const hasAccess = (access) => {
    return isAdmin.value || permissions.value.includes(access);
};

// Sidebar Management overrides
const sidebarSettings = computed(() => usePage().props.sidebarSettings || {});
const wastageApprovalConfig = computed(() => usePage().props.wastageApprovalConfig || {});
const canShowWastageLevel2 = computed(() => wastageApprovalConfig.value.show_level2 !== false);

const isMenuActive = (key) => {
    const setting = sidebarSettings.value[key];
    return setting === undefined ? true : setting.is_active;
};

const menuLabel = (key, defaultLabel) => {
    const setting = sidebarSettings.value[key];
    return (setting && setting.custom_label) ? setting.custom_label : defaultLabel;
};

const getMenuOrder = (key) => {
    const setting = sidebarSettings.value[key];
    return setting?.sort_order ?? 999;
};

// Function to check if a given URL (or any of a list of URLs) is the current active page.
const isPathActive = (pathOrPaths) => {
    const currentUrl = usePage().url.split('?')[0]; // Ignore query strings

    if (Array.isArray(pathOrPaths)) {
        return pathOrPaths.some(p => isPathActive(p));
    }

    const path = pathOrPaths;

    // Exact match
    if (path === currentUrl) {
        return true;
    }

    // Don't match root '/' as a prefix for everything
    if (path === '/') {
        return false;
    }

    // Prefix match for nested routes
    if (currentUrl.startsWith(path) && currentUrl[path.length] === '/') {
        return true;
    }

    return false;
};

// Grouping permissions for collapsible sections

const canViewAdministrationGroup = computed(() =>
    hasAccess("view entities") ||
    hasAccess("view users") ||
    hasAccess("view roles") ||
    hasAccess("manage wastage settings") ||
    hasAccess("manage sidebar") ||
    hasAccess("view import logs") ||
    hasAccess("view items list") ||
    hasAccess("view sapitems list") ||
    hasAccess("view SupplierItems list") ||
    hasAccess("view POSMasterfile list") ||
    hasAccess("view branches") ||
    hasAccess("view suppliers") ||
    hasAccess("view templates") ||
    hasAccess("view ordering template approval") ||
    hasAccess("view month end count templates") ||
    hasAccess("view dts delivery schedules") ||
    hasAccess("view dsp delivery schedules") ||
    hasAccess("view month end schedules") ||
    hasAccess("view orders cutoff") ||
    hasAccess("view knowledge base articles")
);

const canViewMasterfileSubgroup = computed(() =>
    hasAccess("view items list") ||
    hasAccess("view sapitems list") ||
    hasAccess("view SupplierItems list") ||
    hasAccess("view POSMasterfile list") ||
    hasAccess("view branches") ||
    hasAccess("view suppliers")
);

const canViewTemplatesSubgroup = computed(() =>
    hasAccess("view templates") ||
    hasAccess("view ordering template approval") ||
    hasAccess("view month end count templates")
);

const canViewSchedulesSubgroup = computed(() =>
    hasAccess("view dts delivery schedules") ||
    hasAccess("view dsp delivery schedules") ||
    hasAccess("view month end schedules") ||
    hasAccess("view orders cutoff")
);

const canViewOrderingGroup = computed(() =>
    hasAccess("view store orders") ||
    hasAccess("view emergency orders") ||
    hasAccess("view additional orders") ||
    hasAccess("view interco requests") ||
    hasAccess("view dts orders") ||
    hasAccess("view orders for approval list") ||
    hasAccess("view orders for cs approval list") ||
    hasAccess("view additional order approval") ||
    hasAccess("view emergency order approval") ||
    hasAccess("view mass orders") ||
    hasAccess("view cs mass commits") ||
    hasAccess("view dts mass orders") ||
    hasAccess("view cs dts mass commit") ||
    hasAccess("view ordering calendar")
);

// Nested ordering subcategory permissions
const canViewRegularSubcategory = computed(() =>
    hasAccess("view store orders") ||
    hasAccess("view orders for approval list") ||
    hasAccess("view orders for cs approval list")
);

const canViewRegularDTSSubcategory = computed(() =>
    hasAccess("view dts orders")
);

const canViewRegularMassSubcategory = computed(() =>
    hasAccess("view mass orders") ||
    hasAccess("view mass order approval") ||
    hasAccess("view cs mass commits")
);

const canViewStockTransferSubcategory = computed(() =>
    hasAccess("view interco requests") ||
    hasAccess("view interco approvals") ||
    hasAccess("view store commits")
);

const canViewMonitoringSubcategory = computed(() =>
    hasAccess("view ordering calendar")
);

const canViewOthersSubcategory = computed(() =>
    hasAccess("view emergency orders") ||
    hasAccess("view emergency order approval") ||
    hasAccess("view additional orders") ||
    hasAccess("view additional order approval")
);

const canViewReceivingGroup = computed(() =>
    hasAccess("view direct receiving") ||
    hasAccess("view approved orders") ||
    hasAccess("view received orders for approval list") ||
    hasAccess("view approved received items") ||
    hasAccess("view interco receiving")
);

const canViewSalesGroup = computed(() =>
    hasAccess("view store transactions") ||
    hasAccess("view store transactions approval") ||
    hasAccess("view sales budget uploader")
);

const canViewInventoryGroup = computed(() =>
    hasAccess("view stock management") ||
    hasAccess("view soh adjustment") ||
    hasAccess("view wastage record") ||
    hasAccess("view wastage approval level 1") ||
    (canShowWastageLevel2.value && hasAccess("view wastage approval level 2")) ||
    hasAccess("perform month end count") ||
    hasAccess("view month end count approvals") ||
    hasAccess("view month end count approvals level 2") ||
    hasAccess("view low on stocks")
);

const canViewWastageSubgroup = computed(() =>
    hasAccess("view wastage record") ||
    hasAccess("view wastage approval level 1") ||
    (canShowWastageLevel2.value && hasAccess("view wastage approval level 2"))
);

const canViewMECSubgroup = computed(() =>
    hasAccess("perform month end count") ||
    hasAccess("view month end count approvals") ||
    hasAccess("view month end count approvals level 2")
);

const canViewBOMGroup = computed(() =>
    hasAccess("view bom list")
);

const canViewReportsGroup = computed(() =>
    hasAccess("view consolidated so report") ||
    hasAccess("view interco report") ||
    hasAccess("view inventory movement report") ||
    hasAccess("view adoption rate tracking report") ||
    hasAccess("view pmix report") ||
    hasAccess("view wastage report") ||
    hasAccess("view qty variance cost variance report") ||
    hasAccess("view actual cost cogs report") ||
    hasAccess("view delivery report") ||
    hasAccess("view top 10 inventories") ||
    hasAccess("view days inventory outstanding") ||
    hasAccess("view days payable outstanding") ||
    hasAccess("view sales report") ||
    hasAccess("view inventories report") ||
    hasAccess("view upcoming inventories") ||
    hasAccess("view account payable") ||
    hasAccess("view cost of goods") ||
    hasAccess("view items order summary") ||
    hasAccess("view ice cream orders") ||
    hasAccess("view salmon orders") ||
    hasAccess("view fruits and vegetables orders")
);

const canViewReferencesGroup = computed(() =>
    hasAccess("view category list") ||
    hasAccess("view wip list") ||
    hasAccess("view menu categories") ||
    hasAccess("view uom conversions") ||
    hasAccess("view inventory categories") ||
    hasAccess("view unit of measurements") ||
    hasAccess("view cost centers")
);

// Internal refs for open states
const adminOpen = ref(false);
const masterfileOpen = ref(false);
const templatesOpen = ref(false);
const schedulesOpen = ref(false);
const orderingOpen = ref(false);
const receivingOpen = ref(false);
const salesOpen = ref(false);
const inventoryOpen = ref(false);
const wastageOpen = ref(false);
const mecOpen = ref(false);
const bomOpen = ref(false);
const reportsOpen = ref(false);
const referencesOpen = ref(false);

// Nested ordering section states
const regularOpen = ref(false);
const regularDTSOpen = ref(false);
const regularMassOpen = ref(false);
const stockTransferOpen = ref(false);
const monitoringOpen = ref(false);
const othersOpen = ref(false);

// Watch for route changes to automatically open the relevant collapsible section
watchEffect(() => {
    const currentUrl = usePage().url;

    const sections = [
        { ref: adminOpen, paths: ["/entities", "/users", "/roles", "/work-queue", "/items-list", "/sapitems-list", "/SupplierItems-list", "/POSMasterfile-list", "/branches", "/suppliers", "/templates", "/ordering-template-approval", "/month-end-count-templates", "/dts-delivery-schedules", "/dsp-delivery-schedules", "/month-end-schedules", "/orders-cutoff", "/manage-knowledge-base", "/wastage-settings"] },
        { ref: masterfileOpen, paths: ["/items-list", "/sapitems-list", "/SupplierItems-list", "/POSMasterfile-list", "/branches", "/suppliers"] },
        { ref: templatesOpen, paths: ["/templates", "/ordering-template-approval", "/month-end-count-templates"] },
        { ref: schedulesOpen, paths: ["/dts-delivery-schedules", "/dsp-delivery-schedules", "/month-end-schedules", "/orders-cutoff"] },
        { ref: orderingOpen, paths: ["/store-orders", "/emergency-orders", "/additional-orders", "/dts-orders", "/orders-approval", "/cs-approvals", "/additional-orders-approval", "/emergency-orders-approval", "/mass-orders", "/mass-orders-approval", "/cs-mass-commits", "/dts-mass-orders", "/cs-dts-mass-commits", "/interco", "/interco-approval", "/store-commits", "/ordering-calendar", "/ordering-tools/order-calculator"] },
        { ref: receivingOpen, paths: ["/direct-receiving", "/orders-receiving", "/approved-orders", "/receiving-approvals", "/interco-receiving"] },
        { ref: salesOpen, paths: ["/sales-orders", "/store-transactions", "/store-transactions-approval", "/sales-budget-uploader"] },
        { ref: inventoryOpen, paths: ["/stock-management", "/soh-adjustment", "/wastage", "/wastage-approval-level1", "/wastage-approval-level2", "/month-end-count", "/month-end-count-approvals", "/month-end-count-approvals-level2", "/low-on-stocks"] },
        { ref: wastageOpen, paths: ["/wastage", "/wastage-approval-level1", "/wastage-approval-level2"] },
        { ref: mecOpen, paths: ["/month-end-count", "/month-end-count-approvals", "/month-end-count-approvals-level2"] },
        { ref: bomOpen, paths: ["/pos-bom-list"] },
        { ref: reportsOpen, paths: ["/reports/consolidated-so", "/reports/pmix-report", "/reports/interco-report", "/reports/inventory-movement", "/reports/adoption-rate-tracking", "/reports/wastage-report", "/reports/qty-variance-cost-variance-report", "/reports/actual-cost-cogs-report", "/reports/delivery-report", "/top-10-inventories", "/days-inventory-outstanding", "/days-payable-outstanding", "/sales-report", "/inventories-report", "/upcoming-inventories", "/account-payable", "/cost-of-goods", "/product-orders-summary", "/ice-cream-orders", "/salmon-orders", "/fruits-and-vegetables"] },
        { ref: referencesOpen, paths: ["/category-list", "/wip-list", "/menu-categories", "/uom-conversions", "/inventory-categories", "/unit-of-measurements", "/cost-centers"] },
        // Nested ordering sections
        { ref: regularOpen, paths: ["/store-orders", "/orders-approval", "/cs-approvals"] },
        { ref: regularDTSOpen, paths: ["/dts-orders"] },
        { ref: regularMassOpen, paths: ["/mass-orders", "/mass-orders-approval", "/cs-mass-commits"] },
        { ref: stockTransferOpen, paths: ["/interco", "/interco-approval", "/store-commits"] },
        { ref: monitoringOpen, paths: ["/ordering-calendar", "/ordering-tools/order-calculator"] },
        { ref: othersOpen, paths: ["/emergency-orders", "/emergency-orders-approval", "/additional-orders", "/additional-orders-approval"] },
    ];

    sections.forEach(section => {
        const isActive = isPathActive(section.paths);
        section.ref.value = isActive;
    });
});
</script>

<template>
    <nav
        class="flex flex-col items-start pl-4 text-sm font-medium transition-all duration-300 overflow-hidden w-64"
    >
        <!-- Dashboard Link -->
        <div :style="{ order: getMenuOrder('dashboard') }" class="w-full">
            <NavLink v-if="isMenuActive('dashboard')" href="/dashboard" :icon="Home" :is-active="isPathActive('/dashboard')">
                {{ menuLabel('dashboard', 'Dashboard') }}
            </NavLink>
        </div>

        <!-- Ordering Section -->
        <div :style="{ order: getMenuOrder('ordering') }" class="w-full">
        <Collapsible
            v-if="canViewOrderingGroup && isMenuActive('ordering')"
            v-model:open="orderingOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('ordering', 'Ordering') }}</span>
                </div>
                <ChevronDown v-if="orderingOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <div class="flex flex-col">
                <div :style="{ order: getMenuOrder('ordering.regular') }" class="w-full">
                <!-- Regular Subcategory -->
                <Collapsible
                    v-if="canViewRegularSubcategory && isMenuActive('ordering.regular')"
                    v-model:open="regularOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('ordering.regular', 'Regular') }}</span>
                        </div>
                        <ChevronDown v-if="regularOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('ordering.regular.store-orders') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view store orders') && isMenuActive('ordering.regular.store-orders')"
                            href="/store-orders"
                            :icon="ShoppingCart"
                            :is-active="isPathActive('/store-orders')"
                        >
                            {{ menuLabel('ordering.regular.store-orders', 'Store Orders') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.regular.orders-approval') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view orders for approval list') && isMenuActive('ordering.regular.orders-approval')"
                            href="/orders-approval"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/orders-approval')"
                        >
                            {{ menuLabel('ordering.regular.orders-approval', 'Orders Approval') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.regular.cs-approvals') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view orders for cs approval list') && isMenuActive('ordering.regular.cs-approvals')"
                            href="/cs-approvals"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/cs-approvals')"
                        >
                            {{ menuLabel('ordering.regular.cs-approvals', 'CS Review List') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('ordering.regular-dts') }" class="w-full">
                <!-- Regular DTS Subcategory -->
                <Collapsible
                    v-if="canViewRegularDTSSubcategory && isMenuActive('ordering.regular-dts')"
                    v-model:open="regularDTSOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('ordering.regular-dts', 'Regular DTS') }}</span>
                        </div>
                        <ChevronDown v-if="regularDTSOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('ordering.regular-dts.dts-orders') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view dts orders') && isMenuActive('ordering.regular-dts.dts-orders')"
                            href="/dts-orders"
                            :icon="ShoppingBasket"
                            :is-active="isPathActive('/dts-orders')"
                        >
                            {{ menuLabel('ordering.regular-dts.dts-orders', 'DTS Orders') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('ordering.mass') }" class="w-full">
                <!-- Regular Mass Orders Subcategory -->
                <Collapsible
                    v-if="canViewRegularMassSubcategory && isMenuActive('ordering.mass')"
                    v-model:open="regularMassOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('ordering.mass', 'Regular Mass Orders') }}</span>
                        </div>
                        <ChevronDown v-if="regularMassOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('ordering.mass.mass-orders') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view mass orders') && isMenuActive('ordering.mass.mass-orders')"
                            href="/mass-orders"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/mass-orders')"
                        >
                            {{ menuLabel('ordering.mass.mass-orders', 'Mass Orders') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.mass.mass-orders-approval') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view mass order approval') && isMenuActive('ordering.mass.mass-orders-approval')"
                            href="/mass-orders-approval"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/mass-orders-approval')"
                        >
                            {{ menuLabel('ordering.mass.mass-orders-approval', 'Mass Orders Approval') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.mass.cs-mass-commits') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view cs mass commits') && isMenuActive('ordering.mass.cs-mass-commits')"
                            href="/cs-mass-commits"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/cs-mass-commits')"
                        >
                            {{ menuLabel('ordering.mass.cs-mass-commits', 'CS Mass Commits') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('ordering.dts-mass') }" class="w-full">
                <!-- DTS Mass Orders Link -->
                <NavLink
                    v-if="(hasAccess('view dts mass orders') || hasAccess('view cs dts mass commit')) && isMenuActive('ordering.dts-mass')"
                    href="/dts-mass-orders"
                    :icon="SquareChartGantt"
                    :is-active="isPathActive(['/dts-mass-orders', '/cs-dts-mass-commits'])"
                >
                    {{ menuLabel('ordering.dts-mass', 'DTS Mass Orders') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('ordering.stock-transfer') }" class="w-full">
                <!-- Stock Transfer Subcategory -->
                <Collapsible
                    v-if="canViewStockTransferSubcategory && isMenuActive('ordering.stock-transfer')"
                    v-model:open="stockTransferOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('ordering.stock-transfer', 'Stock Transfer') }}</span>
                        </div>
                        <ChevronDown v-if="stockTransferOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('ordering.stock-transfer.interco') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view interco requests') && isMenuActive('ordering.stock-transfer.interco')"
                            href="/interco"
                            :icon="Truck"
                            :is-active="isPathActive('/interco')"
                        >
                            {{ menuLabel('ordering.stock-transfer.interco', 'Interco Transfer') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.stock-transfer.interco-approval') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view interco approvals') && isMenuActive('ordering.stock-transfer.interco-approval')"
                            href="/interco-approval"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/interco-approval')"
                        >
                            {{ menuLabel('ordering.stock-transfer.interco-approval', 'Interco Approval') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.stock-transfer.store-commits') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view store commits') && isMenuActive('ordering.stock-transfer.store-commits')"
                            href="/store-commits"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/store-commits')"
                        >
                            {{ menuLabel('ordering.stock-transfer.store-commits', 'Store Commits') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('ordering.tools') }" class="w-full">
                <!-- Ordering Tools Subcategory -->
                <Collapsible
                    v-if="canViewMonitoringSubcategory && isMenuActive('ordering.tools')"
                    v-model:open="monitoringOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('ordering.tools', 'Ordering Tools') }}</span>
                        </div>
                        <ChevronDown v-if="monitoringOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('ordering.tools.ordering-calendar') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view ordering calendar') && isMenuActive('ordering.tools.ordering-calendar')"
                            href="/ordering-calendar"
                            :icon="CalendarCheck2"
                            :is-active="isPathActive('/ordering-calendar')"
                        >
                            {{ menuLabel('ordering.tools.ordering-calendar', 'Ordering Calendar') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.tools.order-calculator') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view order calculator') && isMenuActive('ordering.tools.order-calculator')"
                            href="/ordering-tools/order-calculator"
                            :icon="Calculator"
                            :is-active="isPathActive('/ordering-tools/order-calculator')"
                        >
                            {{ menuLabel('ordering.tools.order-calculator', 'Order Calculator') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('ordering.others') }" class="w-full">
                <!-- Others Subcategory -->
                <Collapsible
                    v-if="canViewOthersSubcategory && isMenuActive('ordering.others')"
                    v-model:open="othersOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('ordering.others', 'Others') }}</span>
                        </div>
                        <ChevronDown v-if="othersOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('ordering.others.emergency-orders') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view emergency orders') && isMenuActive('ordering.others.emergency-orders')"
                            href="/emergency-orders"
                            :icon="ShoppingCart"
                            :is-active="isPathActive('/emergency-orders')"
                        >
                            {{ menuLabel('ordering.others.emergency-orders', 'Emergency Orders') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.others.emergency-orders-approval') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view emergency order approval') && isMenuActive('ordering.others.emergency-orders-approval')"
                            href="/emergency-orders-approval"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/emergency-orders-approval')"
                        >
                            {{ menuLabel('ordering.others.emergency-orders-approval', 'Emergency Order Approval') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.others.additional-orders') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view additional orders') && isMenuActive('ordering.others.additional-orders')"
                            href="/additional-orders"
                            :icon="ShoppingCart"
                            :is-active="isPathActive('/additional-orders')"
                        >
                            {{ menuLabel('ordering.others.additional-orders', 'Additional Orders') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('ordering.others.additional-orders-approval') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view additional order approval') && isMenuActive('ordering.others.additional-orders-approval')"
                            href="/additional-orders-approval"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/additional-orders-approval')"
                        >
                            {{ menuLabel('ordering.others.additional-orders-approval', 'Additional Order Approval') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
        </div>

        <!-- Receiving Section -->
        <div :style="{ order: getMenuOrder('receiving') }" class="w-full">
        <Collapsible
            v-if="canViewReceivingGroup && isMenuActive('receiving')"
            v-model:open="receivingOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('receiving', 'Receiving') }}</span>
                </div>
                <ChevronDown v-if="receivingOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <div class="flex flex-col">
                <div :style="{ order: getMenuOrder('receiving.direct-receiving') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view direct receiving') && isMenuActive('receiving.direct-receiving')"
                    href="/direct-receiving"
                    :icon="ShoppingBasket"
                    :is-active="isPathActive('/direct-receiving')"
                >
                    {{ menuLabel('receiving.direct-receiving', 'Direct Receiving') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('receiving.inbound-orders') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view approved orders') && isMenuActive('receiving.inbound-orders')"
                    href="/orders-receiving"
                    :icon="ClipboardList"
                    :is-active="isPathActive('/orders-receiving')"
                >
                    {{ menuLabel('receiving.inbound-orders', 'Inbound Orders') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('receiving.approvals') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view received orders for approval list') && isMenuActive('receiving.approvals')"
                    href="/receiving-approvals"
                    :icon="ClipboardCheck"
                    :is-active="isPathActive('/receiving-approvals')"
                >
                    {{ menuLabel('receiving.approvals', 'Receiving Approvals') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('receiving.confirmed-approved') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view approved received items') && isMenuActive('receiving.confirmed-approved')"
                    href="/approved-orders"
                    :icon="FileCheck"
                    :is-active="isPathActive('/approved-orders')"
                >
                    {{ menuLabel('receiving.confirmed-approved', 'Confirmed/Approved Received SO') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('receiving.interco-receiving') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view interco receiving') && isMenuActive('receiving.interco-receiving')"
                    href="/interco-receiving"
                    :icon="Truck"
                    :is-active="isPathActive('/interco-receiving')"
                >
                    {{ menuLabel('receiving.interco-receiving', 'Interco Receiving') }}
                </NavLink>
                </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
        </div>

        <!-- Sales Section -->
        <div :style="{ order: getMenuOrder('sales') }" class="w-full">
        <Collapsible
            v-if="canViewSalesGroup && isMenuActive('sales')"
            v-model:open="salesOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('sales', 'Sales') }}</span>
                </div>
                <ChevronDown v-if="salesOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <div class="flex flex-col">
                <div :style="{ order: getMenuOrder('sales.store-transactions') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view store transactions') && isMenuActive('sales.store-transactions')"
                    href="/store-transactions/summary"
                    :icon="ArrowLeftRight"
                    :is-active="isPathActive('/store-transactions')"
                >
                    {{ menuLabel('sales.store-transactions', 'Store Transactions') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('sales.store-transactions-approval') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view store transactions approval') && isMenuActive('sales.store-transactions-approval')"
                    href="/store-transactions-approval"
                    :icon="ArrowLeftRight"
                    :is-active="isPathActive('/store-transactions-approval')"
                >
                    {{ menuLabel('sales.store-transactions-approval', 'Store Transactions Approval') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('sales.budget-uploader') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view sales budget uploader') && isMenuActive('sales.budget-uploader')"
                    href="/sales-budget-uploader"
                    :icon="FileUp"
                    :is-active="isPathActive('/sales-budget-uploader')"
                >
                    {{ menuLabel('sales.budget-uploader', 'Sales/Budget Uploader') }}
                </NavLink>
                </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
        </div>

        <!-- Inventory Section -->
        <div :style="{ order: getMenuOrder('inventory') }" class="w-full">
        <Collapsible
            v-if="canViewInventoryGroup && isMenuActive('inventory')"
            v-model:open="inventoryOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('inventory', 'Inventory') }}</span>
                </div>
                <ChevronDown v-if="inventoryOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <div class="flex flex-col">
                <div :style="{ order: getMenuOrder('inventory.stock-management') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view stock management') && isMenuActive('inventory.stock-management')"
                    href="/stock-management"
                    :icon="FolderKanban"
                    :is-active="isPathActive('/stock-management')"
                >
                    {{ menuLabel('inventory.stock-management', 'Stock Management') }}
                </NavLink>
                </div>
                <div :style="{ order: getMenuOrder('inventory.soh-adjustment') }" class="w-full">
                <NavLink
                    v-if="hasAccess('view soh adjustment') && isMenuActive('inventory.soh-adjustment')"
                    href="/soh-adjustment"
                    :icon="FolderKanban"
                    :is-active="isPathActive('/soh-adjustment')"
                >
                    {{ menuLabel('inventory.soh-adjustment', 'SOH Adjustment') }}
                </NavLink>
                </div>

                <div :style="{ order: getMenuOrder('inventory.wastage') }" class="w-full">
                <!-- Wastage Subgroup -->
                <Collapsible
                    v-if="canViewWastageSubgroup && isMenuActive('inventory.wastage')"
                    v-model:open="wastageOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('inventory.wastage', 'Wastage') }}</span>
                        </div>
                        <ChevronDown v-if="wastageOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('inventory.wastage.wastage-record') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view wastage record') && isMenuActive('inventory.wastage.wastage-record')"
                            href="/wastage"
                            :icon="Trash2"
                            :is-active="isPathActive('/wastage')"
                        >
                            {{ menuLabel('inventory.wastage.wastage-record', 'Wastage Record') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('inventory.wastage.approval-level1') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view wastage approval level 1') && isMenuActive('inventory.wastage.approval-level1')"
                            href="/wastage-approval-level1"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/wastage-approval-level1')"
                        >
                            {{ menuLabel('inventory.wastage.approval-level1', 'Wastage Approval 1st Level') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('inventory.wastage.approval-level2') }" class="w-full">
                        <NavLink
                            v-if="canShowWastageLevel2 && hasAccess('view wastage approval level 2') && isMenuActive('inventory.wastage.approval-level2')"
                            href="/wastage-approval-level2"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/wastage-approval-level2')"
                        >
                            {{ menuLabel('inventory.wastage.approval-level2', 'Wastage Approval 2nd Level') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('inventory.mec') }" class="w-full">
                <!-- MEC Subgroup -->
                <Collapsible
                    v-if="canViewMECSubgroup && isMenuActive('inventory.mec')"
                    v-model:open="mecOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('inventory.mec', 'MEC') }}</span>
                        </div>
                        <ChevronDown v-if="mecOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('inventory.mec.month-end-count') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('perform month end count') && isMenuActive('inventory.mec.month-end-count')"
                            href="/month-end-count"
                            :icon="ScanBarcode"
                            :is-active="usePage().url.split('?')[0] === '/month-end-count' || usePage().url.split('?')[0].startsWith('/month-end-count/')"
                        >
                            {{ menuLabel('inventory.mec.month-end-count', 'Month End Count') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('inventory.mec.approval-level1') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view month end count approvals') && isMenuActive('inventory.mec.approval-level1')"
                            href="/month-end-count-approvals"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/month-end-count-approvals')"
                        >
                            {{ menuLabel('inventory.mec.approval-level1', 'MEC Approval 1st Level') }}
                        </NavLink>
                        </div>
                        <div :style="{ order: getMenuOrder('inventory.mec.approval-level2') }" class="w-full">
                        <NavLink
                            v-if="hasAccess('view month end count approvals level 2') && isMenuActive('inventory.mec.approval-level2')"
                            href="/month-end-count-approvals-level2"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/month-end-count-approvals-level2')"
                        >
                            {{ menuLabel('inventory.mec.approval-level2', 'MEC Approval 2nd Level') }}
                        </NavLink>
                        </div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('inventory.low-on-stocks') }" class="w-full">
                <NavLink href="/low-on-stocks" :icon="FileCog" v-if="hasAccess('view low on stocks') && isMenuActive('inventory.low-on-stocks')"
                    :is-active="isPathActive('/low-on-stocks')">
                    {{ menuLabel('inventory.low-on-stocks', 'Low on Stocks') }}
                </NavLink>
                </div>
                </div>
            </CollapsibleContent>
        </Collapsible>
        </div>

        <!-- Bill of Materials Section -->
        <div :style="{ order: getMenuOrder('bom') }" class="w-full">
        <Collapsible
            v-if="canViewBOMGroup && isMenuActive('bom')"
            v-model:open="bomOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('bom', 'Bill of Materials') }}</span>
                </div>
                <ChevronDown v-if="bomOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view bom list') && isMenuActive('bom.bom-list')"
                    href="/pos-bom-list"
                    :icon="Scroll"
                    :is-active="isPathActive('/pos-bom-list')"
                >
                    {{ menuLabel('bom.bom-list', 'BOM List') }}
                </NavLink>
            </CollapsibleContent>
        </Collapsible>
        </div>

        <!-- Reports Section -->
        <div :style="{ order: getMenuOrder('reports') }" class="w-full">
        <Collapsible
            v-if="canViewReportsGroup && isMenuActive('reports')"
            v-model:open="reportsOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('reports', 'Reports') }}</span>
                </div>
                <ChevronDown v-if="reportsOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <div class="flex flex-col">
                <div :style="{ order: getMenuOrder('reports.consolidated-so') }" class="w-full"><NavLink v-if="hasAccess('view consolidated so report') && isMenuActive('reports.consolidated-so')" href="/reports/consolidated-so" :icon="List" :is-active="isPathActive('/reports/consolidated-so')">{{ menuLabel('reports.consolidated-so', 'Consolidated SO Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.pmix') }" class="w-full"><NavLink v-if="hasAccess('view pmix report') && isMenuActive('reports.pmix')" href="/reports/pmix-report" :icon="ChartColumnBig" :is-active="isPathActive('/reports/pmix-report')">{{ menuLabel('reports.pmix', 'PMIX Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.wastage') }" class="w-full"><NavLink v-if="hasAccess('view wastage report') && isMenuActive('reports.wastage')" href="/reports/wastage-report" :icon="Trash2" :is-active="isPathActive('/reports/wastage-report')">{{ menuLabel('reports.wastage', 'Wastage Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.delivery') }" class="w-full"><NavLink v-if="hasAccess('view delivery report') && isMenuActive('reports.delivery')" href="/reports/delivery-report" :icon="Truck" :is-active="isPathActive('/reports/delivery-report')">{{ menuLabel('reports.delivery', 'Delivery Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.qty-variance') }" class="w-full"><NavLink v-if="hasAccess('view qty variance cost variance report') && isMenuActive('reports.qty-variance')" href="/reports/qty-variance-cost-variance-report" :icon="ChartColumnBig" :is-active="isPathActive('/reports/qty-variance-cost-variance-report')">{{ menuLabel('reports.qty-variance', 'Qty Variance / Cost Variance Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.actual-cost-cogs') }" class="w-full"><NavLink v-if="hasAccess('view actual cost cogs report') && isMenuActive('reports.actual-cost-cogs')" href="/reports/actual-cost-cogs-report" :icon="Calculator" :is-active="isPathActive('/reports/actual-cost-cogs-report')">{{ menuLabel('reports.actual-cost-cogs', 'Actual Cost / COGS Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.interco') }" class="w-full"><NavLink v-if="hasAccess('view interco report') && isMenuActive('reports.interco')" href="/reports/interco-report" :icon="Truck" :is-active="isPathActive('/reports/interco-report')">{{ menuLabel('reports.interco', 'Interco Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.inventory-movement') }" class="w-full"><NavLink v-if="hasAccess('view inventory movement report') && isMenuActive('reports.inventory-movement')" href="/reports/inventory-movement" :icon="ArrowLeftRight" :is-active="isPathActive('/reports/inventory-movement')">{{ menuLabel('reports.inventory-movement', 'Inventory Movement Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.adoption-rate-tracking') }" class="w-full"><NavLink v-if="hasAccess('view adoption rate tracking report') && isMenuActive('reports.adoption-rate-tracking')" href="/reports/adoption-rate-tracking" :icon="ClipboardCheck" :is-active="isPathActive('/reports/adoption-rate-tracking')">{{ menuLabel('reports.adoption-rate-tracking', 'Adoption Rate Tracking') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.top-10-inventories') }" class="w-full"><NavLink v-if="hasAccess('view top 10 inventories') && isMenuActive('reports.top-10-inventories')" href="/top-10-inventories" :icon="List" :is-active="isPathActive('/top-10-inventories')">{{ menuLabel('reports.top-10-inventories', 'Top 10 Inventories') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.days-inventory-outstanding') }" class="w-full"><NavLink v-if="hasAccess('view days inventory outstanding') && isMenuActive('reports.days-inventory-outstanding')" href="/days-inventory-outstanding" :icon="List" :is-active="isPathActive('/days-inventory-outstanding')">{{ menuLabel('reports.days-inventory-outstanding', 'Days Inventory Outstanding') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.days-payable-outstanding') }" class="w-full"><NavLink v-if="hasAccess('view days payable outstanding') && isMenuActive('reports.days-payable-outstanding')" href="/days-payable-outstanding" :icon="List" :is-active="isPathActive('/days-payable-outstanding')">{{ menuLabel('reports.days-payable-outstanding', 'Days Payable Outstanding') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.sales') }" class="w-full"><NavLink v-if="hasAccess('view sales report') && isMenuActive('reports.sales')" href="/sales-report" :icon="List" :is-active="isPathActive('/sales-report')">{{ menuLabel('reports.sales', 'Sales Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.inventories') }" class="w-full"><NavLink v-if="hasAccess('view inventories report') && isMenuActive('reports.inventories')" href="/inventories-report" :icon="List" :is-active="isPathActive('/inventories-report')">{{ menuLabel('reports.inventories', 'Inventories Report') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.upcoming-inventories') }" class="w-full"><NavLink v-if="hasAccess('view upcoming inventories') && isMenuActive('reports.upcoming-inventories')" href="/upcoming-inventories" :icon="List" :is-active="isPathActive('/upcoming-inventories')">{{ menuLabel('reports.upcoming-inventories', 'Upcoming Inventories') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.account-payable') }" class="w-full"><NavLink v-if="hasAccess('view account payable') && isMenuActive('reports.account-payable')" href="/account-payable" :icon="List" :is-active="isPathActive('/account-payable')">{{ menuLabel('reports.account-payable', 'Account Payable') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.cost-of-goods') }" class="w-full"><NavLink v-if="hasAccess('view cost of goods') && isMenuActive('reports.cost-of-goods')" href="/cost-of-goods" :icon="List" :is-active="isPathActive('/cost-of-goods')">{{ menuLabel('reports.cost-of-goods', 'Cost Of Goods') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.item-orders-summary') }" class="w-full"><NavLink v-if="hasAccess('view items order summary') && isMenuActive('reports.item-orders-summary')" href="/product-orders-summary" :icon="List" :is-active="isPathActive('/product-orders-summary')">{{ menuLabel('reports.item-orders-summary', 'Item Orders Summary') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.ice-cream-orders') }" class="w-full"><NavLink v-if="hasAccess('view ice cream orders') && isMenuActive('reports.ice-cream-orders')" href="/ice-cream-orders" :icon="IceCreamCone" :is-active="isPathActive('/ice-cream-orders')">{{ menuLabel('reports.ice-cream-orders', 'Ice Cream Orders') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.salmon-orders') }" class="w-full"><NavLink v-if="hasAccess('view salmon orders') && isMenuActive('reports.salmon-orders')" href="/salmon-orders" :icon="FishSymbol" :is-active="isPathActive('/salmon-orders')">{{ menuLabel('reports.salmon-orders', 'Salmon Orders') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('reports.fruits-and-vegetables') }" class="w-full"><NavLink v-if="hasAccess('view fruits and vegetables orders') && isMenuActive('reports.fruits-and-vegetables')" href="/fruits-and-vegetables" :icon="Vegan" :is-active="isPathActive('/fruits-and-vegetables')">{{ menuLabel('reports.fruits-and-vegetables', 'Fruits And Vegetables Orders') }}</NavLink></div>
                </div>
            </CollapsibleContent>
        </Collapsible>
        </div>

        <!-- References Section -->
        <div :style="{ order: getMenuOrder('references') }" class="w-full">
        <Collapsible
            v-if="canViewReferencesGroup && isMenuActive('references')"
            v-model:open="referencesOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('references', 'References') }}</span>
                </div>
                <ChevronDown v-if="referencesOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <div class="flex flex-col">
                <div :style="{ order: getMenuOrder('references.categories') }" class="w-full"><NavLink v-if="hasAccess('view category list') && isMenuActive('references.categories')" href="/category-list" :icon="FolderDot" :is-active="isPathActive('/category-list')">{{ menuLabel('references.categories', 'Categories') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('references.wip-list') }" class="w-full"><NavLink v-if="hasAccess('view wip list') && isMenuActive('references.wip-list')" href="/wip-list" :icon="FolderDot" :is-active="isPathActive('/wip-list')">{{ menuLabel('references.wip-list', 'WIP List') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('references.menu-categories') }" class="w-full"><NavLink v-if="hasAccess('view menu categories') && isMenuActive('references.menu-categories')" href="/menu-categories" :icon="FileSliders" :is-active="isPathActive('/menu-categories')">{{ menuLabel('references.menu-categories', 'Menu Categories') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('references.uom-conversions') }" class="w-full"><NavLink v-if="hasAccess('view uom conversions') && isMenuActive('references.uom-conversions')" href="/uom-conversions" :icon="FileSliders" :is-active="isPathActive('/uom-conversions')">{{ menuLabel('references.uom-conversions', 'UOM Conversions') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('references.inventory-categories') }" class="w-full"><NavLink v-if="hasAccess('view inventory categories') && isMenuActive('references.inventory-categories')" href="/inventory-categories" :icon="LayoutList" :is-active="isPathActive('/inventory-categories')">{{ menuLabel('references.inventory-categories', 'Inventory Categories') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('references.unit-of-measurements') }" class="w-full"><NavLink v-if="hasAccess('view unit of measurements') && isMenuActive('references.unit-of-measurements')" href="/unit-of-measurements" :icon="LayoutList" :is-active="isPathActive('/unit-of-measurements')">{{ menuLabel('references.unit-of-measurements', 'Unit of Measurements') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('references.cost-centers') }" class="w-full"><NavLink v-if="hasAccess('view cost centers') && isMenuActive('references.cost-centers')" href="/cost-centers" :icon="TextSelect" :is-active="isPathActive('/cost-centers')">{{ menuLabel('references.cost-centers', 'Cost Centers') }}</NavLink></div>
                </div>
            </CollapsibleContent>
        </Collapsible>
        </div>

        <!-- Administration Section -->
        <div :style="{ order: getMenuOrder('administration') }" class="w-full">
        <Collapsible v-if="canViewAdministrationGroup && isMenuActive('administration')" v-model:open="adminOpen" class="w-full">
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >{{ menuLabel('administration', 'Administration') }}</span>
                </div>
                <ChevronDown v-if="adminOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <div class="flex flex-col">
                <div :style="{ order: getMenuOrder('administration.entities') }" class="w-full"><NavLink v-if="hasAccess('view entities') && isMenuActive('administration.entities')" href="/entities" :icon="Store" :is-active="isPathActive('/entities')">{{ menuLabel('administration.entities', 'Entities') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('administration.users') }" class="w-full"><NavLink v-if="hasAccess('view users') && isMenuActive('administration.users')" href="/users" :icon="UsersRound" :is-active="isPathActive('/users')">{{ menuLabel('administration.users', 'Users') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('administration.roles') }" class="w-full"><NavLink v-if="hasAccess('view roles') && isMenuActive('administration.roles')" href="/roles" :icon="FileCog" :is-active="isPathActive('/roles')">{{ menuLabel('administration.roles', 'Roles') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('administration.work-queue') }" class="w-full"><NavLink v-if="hasAccess('view import logs') && isMenuActive('administration.work-queue')" href="/work-queue" :icon="ClipboardList" :is-active="isPathActive('/work-queue')">{{ menuLabel('administration.work-queue', 'Work Queue') }}</NavLink></div>

                <div :style="{ order: getMenuOrder('administration.masterfile') }" class="w-full">
                <!-- Masterfile Subgroup -->
                <Collapsible
                    v-if="canViewMasterfileSubgroup && isMenuActive('administration.masterfile')"
                    v-model:open="masterfileOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('administration.masterfile', 'Masterfile') }}</span>
                        </div>
                        <ChevronDown v-if="masterfileOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('administration.masterfile.nn-items') }" class="w-full"><NavLink v-if="hasAccess('view items list') && isMenuActive('administration.masterfile.nn-items')" href="/items-list" :icon="PackageSearch" :is-active="isPathActive('/items-list')">{{ menuLabel('administration.masterfile.nn-items', 'NN Inventory Items') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.masterfile.sap') }" class="w-full"><NavLink v-if="hasAccess('view sapitems list') && isMenuActive('administration.masterfile.sap')" href="/sapitems-list" :icon="TextSelect" :is-active="isPathActive('/sapitems-list')">{{ menuLabel('administration.masterfile.sap', 'SAP Masterlist') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.masterfile.supplier-items') }" class="w-full"><NavLink v-if="hasAccess('view SupplierItems list') && isMenuActive('administration.masterfile.supplier-items')" href="/SupplierItems-list" :icon="Warehouse" :is-active="isPathActive('/SupplierItems-list')">{{ menuLabel('administration.masterfile.supplier-items', 'Supplier Items') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.masterfile.pos') }" class="w-full"><NavLink v-if="hasAccess('view POSMasterfile list') && isMenuActive('administration.masterfile.pos')" href="/POSMasterfile-list" :icon="TextSelect" :is-active="isPathActive('/POSMasterfile-list')">{{ menuLabel('administration.masterfile.pos', 'POS Masterlist') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.masterfile.branches') }" class="w-full"><NavLink v-if="hasAccess('view branches') && isMenuActive('administration.masterfile.branches')" href="/branches" :icon="AppWindowMac" :is-active="isPathActive('/branches')">{{ menuLabel('administration.masterfile.branches', 'Store Branches') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.masterfile.suppliers') }" class="w-full"><NavLink v-if="hasAccess('view suppliers') && isMenuActive('administration.masterfile.suppliers')" href="/suppliers" :icon="Warehouse" :is-active="isPathActive('/suppliers')">{{ menuLabel('administration.masterfile.suppliers', 'Suppliers') }}</NavLink></div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('administration.templates') }" class="w-full">
                <!-- Templates Subgroup -->
                <Collapsible
                    v-if="canViewTemplatesSubgroup && isMenuActive('administration.templates')"
                    v-model:open="templatesOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('administration.templates', 'Templates') }}</span>
                        </div>
                        <ChevronDown v-if="templatesOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('administration.templates.ordering') }" class="w-full"><NavLink v-if="hasAccess('view templates') && isMenuActive('administration.templates.ordering')" href="/templates" :icon="FileCog" :is-active="isPathActive('/templates')">{{ menuLabel('administration.templates.ordering', 'Ordering Templates') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.templates.ordering-approval') }" class="w-full"><NavLink v-if="hasAccess('view ordering template approval') && isMenuActive('administration.templates.ordering-approval')" href="/ordering-template-approval" :icon="FileCheck" :is-active="isPathActive('/ordering-template-approval')">{{ menuLabel('administration.templates.ordering-approval', 'Ordering Template Approval') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.templates.mec') }" class="w-full"><NavLink v-if="hasAccess('view month end count templates') && isMenuActive('administration.templates.mec')" href="/month-end-count-templates" :icon="Scroll" :is-active="isPathActive('/month-end-count-templates')">{{ menuLabel('administration.templates.mec', 'Month End Count Templates') }}</NavLink></div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>
                </div>
                <div :style="{ order: getMenuOrder('administration.schedules') }" class="w-full">
                <!-- Schedules Subgroup -->
                <Collapsible
                    v-if="canViewSchedulesSubgroup && isMenuActive('administration.schedules')"
                    v-model:open="schedulesOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">{{ menuLabel('administration.schedules', 'Schedules') }}</span>
                        </div>
                        <ChevronDown v-if="schedulesOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <div class="flex flex-col">
                        <div :style="{ order: getMenuOrder('administration.schedules.dts-delivery') }" class="w-full"><NavLink v-if="hasAccess('view dts delivery schedules') && isMenuActive('administration.schedules.dts-delivery')" href="/dts-delivery-schedules" :icon="CalendarCheck2" :is-active="isPathActive('/dts-delivery-schedules')">{{ menuLabel('administration.schedules.dts-delivery', 'DTS Delivery Schedules') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.schedules.dsp-delivery') }" class="w-full"><NavLink v-if="hasAccess('view dsp delivery schedules') && isMenuActive('administration.schedules.dsp-delivery')" href="/dsp-delivery-schedules" :icon="CalendarCheck2" :is-active="isPathActive('/dsp-delivery-schedules')">{{ menuLabel('administration.schedules.dsp-delivery', 'Delivery Schedules') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.schedules.month-end') }" class="w-full"><NavLink v-if="hasAccess('view month end schedules') && isMenuActive('administration.schedules.month-end')" href="/month-end-schedules" :icon="CalendarCheck2" :is-active="isPathActive('/month-end-schedules')">{{ menuLabel('administration.schedules.month-end', 'Month End Count Schedules') }}</NavLink></div>
                        <div :style="{ order: getMenuOrder('administration.schedules.orders-cutoff') }" class="w-full"><NavLink v-if="hasAccess('view orders cutoff') && isMenuActive('administration.schedules.orders-cutoff')" href="/orders-cutoff" :icon="CalendarCheck2" :is-active="isPathActive('/orders-cutoff')">{{ menuLabel('administration.schedules.orders-cutoff', 'Ordering Cut off') }}</NavLink></div>
                        </div>
                    </CollapsibleContent>
                </Collapsible>

                </div>
                <div :style="{ order: getMenuOrder('administration.knowledge-base') }" class="w-full"><NavLink v-if="hasAccess('view knowledge base articles') && isMenuActive('administration.knowledge-base')" href="/manage-knowledge-base" :icon="FileCheck" :is-active="isPathActive('/manage-knowledge-base')">{{ menuLabel('administration.knowledge-base', 'Knowledge Base Articles') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('administration.wastage-settings') }" class="w-full"><NavLink v-if="hasAccess('manage wastage settings') && isMenuActive('administration.wastage-settings')" href="/wastage-settings" :icon="MonitorCog" :is-active="isPathActive('/wastage-settings')">{{ menuLabel('administration.wastage-settings', 'Wastage Settings') }}</NavLink></div>
                <div :style="{ order: getMenuOrder('administration.sidebar-management') }" class="w-full"><NavLink v-if="hasAccess('manage sidebar') && isMenuActive('administration.sidebar-management')" href="/sidebar-management" :icon="MonitorCog" :is-active="isPathActive('/sidebar-management')">{{ menuLabel('administration.sidebar-management', 'Sidebar Management') }}</NavLink></div>
                </div>
            </CollapsibleContent>
        </Collapsible>
        </div>
    </nav>
</template>
