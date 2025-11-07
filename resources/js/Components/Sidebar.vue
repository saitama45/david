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
    AppWindowMac,
    Warehouse,
    TextSelect,
    Truck,
    ChevronDown,
    ChevronRight,
} from "lucide-vue-next";

const { is_admin } = usePage().props.auth;
const permissions = usePage().props.auth.permissions;

// Helper function to check if the current user has a specific permission.
const hasAccess = (access) => {
    return permissions.includes(access);
};
const isAdmin = is_admin;

// Function to check if a given URL (or any of a list of URLs) is the current active page.
// This uses usePage().url which gives the full current URL path (e.g., /users/1/edit).
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

    // Prefix match for nested routes, but not for partial matches like
    // '/users' matching '/users-roles'. It must be followed by a '/'.
    if (currentUrl.startsWith(path) && currentUrl[path.length] === '/') {
        return true;
    }

    return false;
};

// Grouping permissions for collapsible sections
// These computed properties determine if a main collapsible section should be visible.
// A section is visible if the user has access to ANY of the items within that section.

const canViewSettingsGroup = computed(() =>
    hasAccess("view users") ||
    hasAccess("view roles") ||
    hasAccess("view templates") ||
    hasAccess("view dts delivery schedules") ||
    hasAccess("view dsp delivery schedules") ||
    hasAccess("view orders cutoff") ||
    hasAccess("view month end schedules")
);

const canViewOrderingGroup = computed(() =>
    hasAccess("view store orders") ||
    hasAccess("view emergency orders") ||
    hasAccess("view additional orders") ||
    hasAccess("view interco requests") ||
    hasAccess("view dts orders") ||
    hasAccess("view orders for approval list") ||
    hasAccess("view orders for cs approval list") || // CS Review List
    hasAccess("view additional order approval") ||
    hasAccess("view emergency order approval") ||
    hasAccess("view mass orders") ||
    hasAccess("view cs mass commits") ||
    hasAccess("view dts mass orders") ||
    hasAccess("view cs dts mass commit")
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
    hasAccess("view cs mass commits")
);

const canViewDTSMassSubcategory = computed(() =>
    hasAccess("view dts mass orders") ||
    hasAccess("view cs dts mass commit")
);

const canViewStockTransferSubcategory = computed(() =>
    hasAccess("view interco requests") ||
    hasAccess("view interco approvals") ||
    hasAccess("view store commits")
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
    hasAccess("view store transactions approval")
);

const canViewInventoryGroup = computed(() =>
    hasAccess("view items list") ||
    hasAccess("view sapitems list") ||
    hasAccess("view SupplierItems list") ||
    hasAccess("view POSMasterfile list") ||
    hasAccess("view bom list") || // Corrected from 'view menu list'
    hasAccess("view stock management") ||
    hasAccess("view soh adjustment") ||
    hasAccess("view low on stocks") || // NEW
    hasAccess("perform month end count") || // NEW
    hasAccess("view month end count approvals") || // NEW
    hasAccess("view month end count approvals level 2")
);

const canViewReportsGroup = computed(() =>
    hasAccess("view consolidated so report") || // CRITICAL FIX: Added new permission for Consolidated SO Report
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
    hasAccess("view branches") ||
    hasAccess("view suppliers") ||
    hasAccess("view cost centers")
);

// Internal refs to store the open/closed state for each section.
// These are directly controlled by v-model:open on Collapsible.
const settingsOpen = ref(false);
const orderingOpen = ref(false);
const receivingOpen = ref(false);
const salesOpen = ref(false);
const inventoryOpen = ref(false);
const reportsOpen = ref(false);
const referencesOpen = ref(false);

// Nested ordering section states
const regularOpen = ref(false);
const regularDTSOpen = ref(false);
const regularMassOpen = ref(false);
const dtsMassOpen = ref(false);
const stockTransferOpen = ref(false);
const othersOpen = ref(false);

// Watch for route changes to automatically open the relevant collapsible section
watchEffect(() => {
    const currentUrl = usePage().url;

    // Define all collapsible sections and their associated paths
    const sections = [
        { ref: settingsOpen, paths: ["/users", "/roles", "/templates", "/dts-delivery-schedules", "/dsp-delivery-schedules", "/orders-cutoff", "/month-end-schedules"] },
        { ref: orderingOpen, paths: ["/store-orders", "/emergency-orders", "/additional-orders", "/dts-orders", "/orders-approval", "/cs-approvals", "/additional-orders-approval", "/emergency-orders-approval", "/mass-orders", "/cs-mass-commits", "/dts-mass-orders", "/cs-dts-mass-commits", "/interco", "/interco-approval", "/store-commits"] },
        { ref: receivingOpen, paths: ["/direct-receiving", "/orders-receiving", "/approved-orders", "/receiving-approvals", "/interco-receiving"] },
        { ref: salesOpen, paths: ["/sales-orders", "/store-transactions", "/store-transactions-approval"] },
        { ref: inventoryOpen, paths: ["/items-list", "/sapitems-list", "/SupplierItems-list", "/POSMasterfile-list", "/pos-bom-list", "/stock-management", "/soh-adjustment", "/low-on-stocks", "/month-end-count", "/month-end-count-approvals", "/month-end-count-approvals-level2"] },
        { ref: reportsOpen, paths: ["/reports/consolidated-so", "/top-10-inventories", "/days-inventory-outstanding", "/days-payable-outstanding", "/sales-report", "/inventories-report", "/upcoming-inventories", "/account-payable", "/cost-of-goods", "/product-orders-summary", "/ice-cream-orders", "/salmon-orders", "/fruits-and-vegetables"] }, // CRITICAL FIX: Added new path
        { ref: referencesOpen, paths: ["/category-list", "/wip-list", "/menu-categories", "/uom-conversions", "/inventory-categories", "/unit-of-measurements", "/branches", "/suppliers", "/cost-centers"] },
        // Nested ordering sections
        { ref: regularOpen, paths: ["/store-orders", "/orders-approval", "/cs-approvals"] },
        { ref: regularDTSOpen, paths: ["/dts-orders"] },
        { ref: regularMassOpen, paths: ["/mass-orders", "/cs-mass-commits"] },
        { ref: dtsMassOpen, paths: ["/dts-mass-orders", "/cs-dts-mass-commits"] },
        { ref: stockTransferOpen, paths: ["/interco", "/interco-approval", "/store-commits"] },
        { ref: othersOpen, paths: ["/emergency-orders", "/emergency-orders-approval", "/additional-orders", "/additional-orders-approval"] },
    ];

    sections.forEach(section => {
        const isActive = isPathActive(section.paths);
        section.ref.value = isActive; // Set the section's open state based on active path
    });
});
</script>

<template>
    <nav
        class="grid items-start pl-4 text-sm font-medium transition-all duration-300 overflow-hidden w-64"
    >
        <!-- Removed Sidebar Toggle Button -->

        <!-- Dashboard Link -->
        <NavLink href="/dashboard" :icon="Home" :is-active="isPathActive('/dashboard')">
            Dashboard
        </NavLink>
        <!-- Audits Link (currently hidden) -->
        <NavLink href="/audits" :icon="MonitorCog" v-if="false" :is-active="isPathActive('/audits')">
            Audits
        </NavLink>

        <!-- Ordering Section -->
        <Collapsible
            v-if="canViewOrderingGroup"
            v-model:open="orderingOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >Ordering</span>
                </div>
                <ChevronDown v-if="orderingOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <!-- Regular Subcategory -->
                <Collapsible
                    v-if="canViewRegularSubcategory"
                    v-model:open="regularOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">Regular</span>
                        </div>
                        <ChevronDown v-if="regularOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <NavLink
                            v-if="hasAccess('view store orders')"
                            href="/store-orders"
                            :icon="ShoppingCart"
                            :is-active="isPathActive('/store-orders')"
                        >
                            Store Orders
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view orders for approval list')"
                            href="/orders-approval"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/orders-approval')"
                        >
                            Orders Approval
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view orders for cs approval list')"
                            href="/cs-approvals"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/cs-approvals')"
                        >
                            CS Review List
                        </NavLink>
                    </CollapsibleContent>
                </Collapsible>

                <!-- Regular DTS Subcategory -->
                <Collapsible
                    v-if="canViewRegularDTSSubcategory"
                    v-model:open="regularDTSOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">Regular DTS</span>
                        </div>
                        <ChevronDown v-if="regularDTSOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <NavLink
                            v-if="hasAccess('view dts orders')"
                            href="/dts-orders"
                            :icon="ShoppingBasket"
                            :is-active="isPathActive('/dts-orders')"
                        >
                            DTS Orders
                        </NavLink>
                    </CollapsibleContent>
                </Collapsible>

                <!-- Regular Mass Subcategory -->
                <Collapsible
                    v-if="canViewRegularMassSubcategory"
                    v-model:open="regularMassOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">Regular Mass</span>
                        </div>
                        <ChevronDown v-if="regularMassOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <NavLink
                            v-if="hasAccess('view mass orders')"
                            href="/mass-orders"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/mass-orders')"
                        >
                            Mass Orders
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view cs mass commits')"
                            href="/cs-mass-commits"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/cs-mass-commits')"
                        >
                            CS Mass Commits
                        </NavLink>
                    </CollapsibleContent>
                </Collapsible>

                <!-- DTS Mass Subcategory -->
                <Collapsible
                    v-if="canViewDTSMassSubcategory"
                    v-model:open="dtsMassOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">DTS Mass</span>
                        </div>
                        <ChevronDown v-if="dtsMassOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <NavLink
                            v-if="hasAccess('view dts mass orders')"
                            href="/dts-mass-orders"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/dts-mass-orders')"
                        >
                            DTS Mass Orders
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view cs dts mass commit')"
                            href="/cs-dts-mass-commits"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/cs-dts-mass-commits')"
                        >
                            CS DTS Mass Commits
                        </NavLink>
                    </CollapsibleContent>
                </Collapsible>

                <!-- Stock Transfer Subcategory -->
                <Collapsible
                    v-if="canViewStockTransferSubcategory"
                    v-model:open="stockTransferOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">Stock Transfer</span>
                        </div>
                        <ChevronDown v-if="stockTransferOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <NavLink
                            v-if="hasAccess('view interco requests')"
                            href="/interco"
                            :icon="Truck"
                            :is-active="isPathActive('/interco')"
                        >
                            Interco Transfer
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view interco approvals')"
                            href="/interco-approval"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/interco-approval')"
                        >
                            Interco Approval
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view store commits')"
                            href="/store-commits"
                            :icon="ClipboardCheck"
                            :is-active="isPathActive('/store-commits')"
                        >
                            Store Commits
                        </NavLink>
                    </CollapsibleContent>
                </Collapsible>

                <!-- Others Subcategory -->
                <Collapsible
                    v-if="canViewOthersSubcategory"
                    v-model:open="othersOpen"
                    class="w-full"
                >
                    <CollapsibleTrigger
                        class="flex items-center justify-between w-full py-1 text-xs hover:bg-muted/30 rounded-md px-2"
                    >
                        <div class="flex items-center">
                            <span class="text-muted-foreground">Others</span>
                        </div>
                        <ChevronDown v-if="othersOpen" class="h-3 w-3" />
                        <ChevronRight v-else class="h-3 w-3" />
                    </CollapsibleTrigger>
                    <CollapsibleContent class="pl-2">
                        <NavLink
                            v-if="hasAccess('view emergency orders')"
                            href="/emergency-orders"
                            :icon="ShoppingCart"
                            :is-active="isPathActive('/emergency-orders')"
                        >
                            Emergency Orders
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view emergency order approval')"
                            href="/emergency-orders-approval"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/emergency-orders-approval')"
                        >
                            Emergency Order Approval
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view additional orders')"
                            href="/additional-orders"
                            :icon="ShoppingCart"
                            :is-active="isPathActive('/additional-orders')"
                        >
                            Additional Orders
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view additional order approval')"
                            href="/additional-orders-approval"
                            :icon="SquareChartGantt"
                            :is-active="isPathActive('/additional-orders-approval')"
                        >
                            Additional Order Approval
                        </NavLink>
                    </CollapsibleContent>
                </Collapsible>
            </CollapsibleContent>
        </Collapsible>

        <!-- Receiving Section -->
        <Collapsible
            v-if="canViewReceivingGroup"
            v-model:open="receivingOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >Receiving</span>
                </div>
                <ChevronDown v-if="receivingOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink href="/direct-receiving" :icon="ShoppingBasket" :is-active="isPathActive('/direct-receiving')">
                    Direct Receiving
                </NavLink>
                <NavLink
                    v-if="hasAccess('view approved orders')"
                    href="/orders-receiving"
                    :icon="ClipboardList"
                    :is-active="isPathActive('/orders-receiving')"
                >
                    Inbound Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view received orders for approval list')"
                    href="/receiving-approvals"
                    :icon="ClipboardCheck"
                    :is-active="isPathActive('/receiving-approvals')"
                >
                    Receiving Approvals
                </NavLink>
                <NavLink
                    v-if="hasAccess('view approved received items')"
                    href="/approved-orders"
                    :icon="FileCheck"
                    :is-active="isPathActive('/approved-orders')"
                >
                    Confirmed/Approved Received SO
                </NavLink>
                <NavLink
                    v-if="hasAccess('view interco receiving')"
                    href="/interco-receiving"
                    :icon="Truck"
                    :is-active="isPathActive('/interco-receiving')"
                >
                    Interco Receiving
                </NavLink>
            </CollapsibleContent>
        </Collapsible>

        <!-- Sales Section -->
        <Collapsible
            v-if="canViewSalesGroup"
            v-model:open="salesOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >Sales</span>
                </div>
                <ChevronDown v-if="salesOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="false"
                    href="/sales-orders"
                    :icon="ChartColumnBig"
                    :is-active="isPathActive('/sales-orders')"
                >
                    Sales Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view store transactions')"
                    href="/store-transactions/summary"
                    :icon="ArrowLeftRight"
                    :is-active="isPathActive('/store-transactions')"
                >
                    Store Transactions
                </NavLink>
                <NavLink
                    v-if="hasAccess('view store transactions approval')"
                    href="/store-transactions-approval"
                    :icon="ArrowLeftRight"
                    :is-active="isPathActive('/store-transactions-approval')"
                >
                    Store Transactions Approval
                </NavLink>
            </CollapsibleContent>
        </Collapsible>

        <!-- Inventory Section -->
        <Collapsible
            v-if="canViewInventoryGroup"
            v-model:open="inventoryOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >Inventory</span>
                </div>
                <ChevronDown v-if="inventoryOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view items list')"
                    href="/items-list"
                    :icon="PackageSearch"
                    :is-active="isPathActive('/items-list')"
                >
                    NN Inventory Items
                </NavLink>
                <NavLink
                    v-if="hasAccess('view sapitems list')"
                    href="/sapitems-list"
                    :icon="TextSelect"
                    :is-active="isPathActive('/sapitems-list')"
                >
                    SAP Mastlist Items
                </NavLink>
                <NavLink
                    v-if="hasAccess('view SupplierItems list')"
                    href="/SupplierItems-list"
                    :icon="Warehouse"
                    :is-active="isPathActive('/SupplierItems-list')"
                >
                    Supplier Items
                </NavLink>
                <NavLink
                    v-if="hasAccess('view POSMasterfile list')"
                    href="/POSMasterfile-list"
                    :icon="TextSelect"
                    :is-active="isPathActive('/POSMasterfile-list')"
                >
                    POS Masterlist
                </NavLink>
                <NavLink
                    v-if="hasAccess('view POSMasterfile BOM list')"
                    href="/pos-bom-list"
                    :icon="Scroll"
                    :is-active="isPathActive('/pos-bom-list')"
                >
                    BOM
                </NavLink>
                <NavLink
                    v-if="hasAccess('view stock management')"
                    href="/stock-management"
                    :icon="FolderKanban"
                    :is-active="isPathActive('/stock-management')"
                >
                    Stock Management
                </NavLink>
                <NavLink
                    v-if="hasAccess('view soh adjustment')"
                    href="/soh-adjustment"
                    :icon="FolderKanban"
                    :is-active="isPathActive('/soh-adjustment')"
                >
                    SOH Adjustment
                </NavLink>
                <!-- Moved Low on Stocks here -->
                <NavLink href="/low-on-stocks" :icon="FileCog" v-if="hasAccess('view low on stocks')"
                    :is-active="isPathActive('/low-on-stocks')">
                    Low on Stocks
                </NavLink>
                <!-- NEW: Month End Count -->
                <NavLink
                    v-if="hasAccess('perform month end count')"
                    href="/month-end-count"
                    :icon="ScanBarcode"
                    :is-active="usePage().url.split('?')[0] === '/month-end-count' || usePage().url.split('?')[0].startsWith('/month-end-count/')"
                >
                    Month End Count
                </NavLink>
                <!-- NEW: Month End Count Approvals -->
                <NavLink
                    v-if="hasAccess('view month end count approvals')"
                    href="/month-end-count-approvals"
                    :icon="ClipboardCheck"
                    :is-active="isPathActive('/month-end-count-approvals')"
                >
                    MEC Approval 1st Level
                </NavLink>
                <!-- NEW: MEC Approval 2nd Level -->
                <NavLink
                    v-if="hasAccess('view month end count approvals level 2')"
                    href="/month-end-count-approvals-level2"
                    :icon="ClipboardCheck"
                    :is-active="isPathActive('/month-end-count-approvals-level2')"
                >
                    MEC Approval 2nd Level
                </NavLink>
            </CollapsibleContent>
        </Collapsible>

        <!-- Reports Section -->
        <Collapsible
            v-if="canViewReportsGroup"
            v-model:open="reportsOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >Reports</span>
                </div>
                <ChevronDown v-if="reportsOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <!-- CRITICAL FIX: Added new NavLink for Consolidated SO Report -->
                <NavLink
                    v-if="hasAccess('view consolidated so report')"
                    href="/reports/consolidated-so"
                    :icon="List"
                    :is-active="isPathActive('/reports/consolidated-so')"
                >
                    Consolidated SO Report
                </NavLink>
                <NavLink v-if="hasAccess('view top 10 inventories')" href="/top-10-inventories" :icon="List" :is-active="isPathActive('/top-10-inventories')">
                    Top 10 Inventories
                </NavLink>
                <NavLink v-if="hasAccess('view days inventory outstanding')" href="/days-inventory-outstanding" :icon="List" :is-active="isPathActive('/days-inventory-outstanding')">
                    Days Inventory Outstanding
                </NavLink>
                <NavLink v-if="hasAccess('view days payable outstanding')" href="/days-payable-outstanding" :icon="List" :is-active="isPathActive('/days-payable-outstanding')">
                    Days Payable Outstanding
                </NavLink>
                <NavLink v-if="hasAccess('view sales report')" href="/sales-report" :icon="List" :is-active="isPathActive('/sales-report')">
                    Sales Report
                </NavLink>
                <NavLink v-if="hasAccess('view inventories report')" href="/inventories-report" :icon="List" :is-active="isPathActive('/inventories-report')">
                    Inventories Report
                </NavLink>
                <NavLink v-if="hasAccess('view upcoming inventories')" href="/upcoming-inventories" :icon="List" :is-active="isPathActive('/upcoming-inventories')">
                    Upcoming Inventories
                </NavLink>
                <NavLink v-if="hasAccess('view account payable')" href="/account-payable" :icon="List" :is-active="isPathActive('/account-payable')">
                    Account Payable
                </NavLink>
                <NavLink v-if="hasAccess('view cost of goods')" href="/cost-of-goods" :icon="List" :is-active="isPathActive('/cost-of-goods')">
                    Cost Of Goods
                </NavLink>
                <NavLink
                    v-if="hasAccess('view items order summary')"
                    href="/product-orders-summary"
                    :icon="List"
                    :is-active="isPathActive('/product-orders-summary')"
                >
                    Item Orders Summary
                </NavLink>
                <NavLink
                    v-if="hasAccess('view ice cream orders')"
                    href="/ice-cream-orders"
                    :icon="IceCreamCone"
                    :is-active="isPathActive('/ice-cream-orders')"
                >
                    Ice Cream Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view salmon orders')"
                    href="/salmon-orders"
                    :icon="FishSymbol"
                    :is-active="isPathActive('/salmon-orders')"
                >
                    Salmon Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view fruits and vegetables orders')"
                    href="/fruits-and-vegetables"
                    :icon="Vegan"
                    :is-active="isPathActive('/fruits-and-vegetables')"
                >
                    Fruits And Vegetables Orders
                </NavLink>
            </CollapsibleContent>
        </Collapsible>

        <!-- References Section -->
        <Collapsible
            v-if="canViewReferencesGroup"
            v-model:open="referencesOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >References</span>
                </div>
                <ChevronDown v-if="referencesOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view category list')"
                    href="/category-list"
                    :icon="FolderDot"
                    :is-active="isPathActive('/category-list')"
                >
                    Categories
                </NavLink>
                <NavLink v-if="hasAccess('view wip list')" href="/wip-list" :icon="FolderDot" :is-active="isPathActive('/wip-list')"> WIP List </NavLink>
                <NavLink
                    v-if="hasAccess('view menu categories')"
                    href="/menu-categories"
                    :icon="FileSliders"
                    :is-active="isPathActive('/menu-categories')"
                >
                    Menu Categories
                </NavLink>
                <NavLink v-if="hasAccess('view uom conversions')" href="/uom-conversions" :icon="FileSliders" :is-active="isPathActive('/uom-conversions')">
                    UOM Conversions
                </NavLink>
                <NavLink
                    v-if="hasAccess('view inventory categories')"
                    href="/inventory-categories"
                    :icon="LayoutList"
                    :is-active="isPathActive('/inventory-categories')"
                >
                    Invetory Categories
                </NavLink>
                <NavLink
                    v-if="hasAccess('view unit of measurements')"
                    href="/unit-of-measurements"
                    :icon="LayoutList"
                    :is-active="isPathActive('/unit-of-measurements')"
                >
                    Unit of Measurements
                </NavLink>
                <NavLink
                    v-if="hasAccess('view branches')"
                    href="/branches"
                    :icon="AppWindowMac"
                    :is-active="isPathActive('/branches')"
                >
                    Store Branches
                </NavLink>
                <NavLink
                    v-if="hasAccess('view suppliers')"
                    href="/suppliers"
                    :icon="Warehouse"
                    :is-active="isPathActive('/suppliers')"
                >
                    Suppliers
                </NavLink>
                <NavLink
                    v-if="hasAccess('view cost centers')"
                    href="/cost-centers"
                    :icon="TextSelect"
                    :is-active="isPathActive('/cost-centers')"
                >
                    Cost Centers
                </NavLink>
            </CollapsibleContent>
        </Collapsible>

        <!-- Settings Section -->
        <Collapsible v-if="canViewSettingsGroup" v-model:open="settingsOpen" class="w-full">
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span >Settings</span>
                </div>
                <ChevronDown v-if="settingsOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view users')"
                    href="/users"
                    :icon="UsersRound"
                    :is-active="isPathActive('/users')"
                >
                    Users
                </NavLink>
                <NavLink href="/roles" :icon="FileCog" v-if="hasAccess('view roles')" :is-active="isPathActive('/roles')">
                    Roles
                </NavLink>
                <NavLink
                    v-if="hasAccess('view templates')"
                    href="/templates"
                    :icon="FileCog"
                    :is-active="isPathActive('/templates')"
                >
                    Templates
                </NavLink>
                <NavLink
                    v-if="hasAccess('view dts delivery schedules')"
                    href="/dts-delivery-schedules"
                    :icon="CalendarCheck2"
                    :is-active="isPathActive('/dts-delivery-schedules')"
                >
                    DTS Delivery Schedules
                </NavLink>
                <NavLink
                    v-if="hasAccess('view dsp delivery schedules')"
                    href="/dsp-delivery-schedules"
                    :icon="CalendarCheck2"
                    :is-active="isPathActive('/dsp-delivery-schedules')"
                >
                    Delivery Schedules
                </NavLink>
                <NavLink
                    v-if="hasAccess('view orders cutoff')"
                    href="/orders-cutoff"
                    :icon="CalendarCheck2"
                    :is-active="isPathActive('/orders-cutoff')"
                >
                    Ordering Cut Off
                </NavLink>
                <NavLink
                    v-if="hasAccess('view month end schedules')"
                    href="/month-end-schedules"
                    :icon="CalendarCheck2"
                    :is-active="isPathActive('/month-end-schedules')"
                >
                    Month End Schedules
                </NavLink>
            </CollapsibleContent>
        </Collapsible>
    </nav>
</template>