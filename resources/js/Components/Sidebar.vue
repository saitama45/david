<script setup>
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from "@/components/ui/collapsible";

import NavLink from "./NavLink.vue";
import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
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
    ChevronDown,
    ChevronRight,
} from "lucide-vue-next";

const { is_admin } = usePage().props.auth;
const permissions = usePage().props.auth.permissions;
const hasAccess = (access) => {
    return permissions.includes(access);
};
const isAdmin = is_admin;

const canViewOrderingGroup =
    hasAccess("view store orders") ||
    hasAccess("view dts orders") ||
    hasAccess("view orders for approval list");

const canViewReceivingGroup =
    hasAccess("view approved orders") ||
    hasAccess("view received orders for approval list") ||
    hasAccess("view approved received items");

const canViewSalesGroup = hasAccess("view store transactions");

const canViewInventoryGroup =
    hasAccess("view items list") ||
    hasAccess("view menu list") ||
    hasAccess("view stock management");

const canViewReportsGroup =
    hasAccess("view items order summary") ||
    hasAccess("view ice cream orders") ||
    hasAccess("view salmon orders") ||
    hasAccess("view fruits and vegetables orders");

const canViewReferenceGroup = hasAccess("manage references");

// State for collapsible sections
const schedulesOpen = ref(false);
const orderingOpen = ref(false);
const receivingOpen = ref(false);
const salesOpen = ref(false);
const inventoryOpen = ref(false);
const reportsOpen = ref(false);
const referenceOpen = ref(false);
</script>

<template>
    <nav class="grid items-start pl-4 text-sm font-medium">
        <NavLink href="/dashboard" :icon="Home"> Dashboard </NavLink>
        <NavLink
            v-if="hasAccess('view users')"
            href="/users"
            :icon="UsersRound"
        >
            Users
        </NavLink>
        <NavLink
            href="/low-on-stocks"
            :icon="FileCog"
            v-if="hasAccess('view roles')"
        >
            Low on Stocks
        </NavLink>
        <NavLink href="/roles" :icon="FileCog" v-if="hasAccess('view roles')">
            Roles
        </NavLink>
        <NavLink
            href="/templates"
            :icon="FileCog"
            v-if="hasAccess('view roles')"
        >
            Templates
        </NavLink>
        <NavLink href="/audits" :icon="MonitorCog" v-if="false">
            Audits
        </NavLink>

        <!-- Schedules Section -->
        <Collapsible v-if="isAdmin" v-model:open="schedulesOpen" class="w-full">
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span>Schedules</span>
                </div>
                <ChevronDown v-if="schedulesOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view dts delivery schedules')"
                    href="/delivery-schedules"
                    :icon="CalendarCheck2"
                >
                    DTS Delivery Schedules
                </NavLink>
            </CollapsibleContent>
        </Collapsible>

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
                    <span>Ordering</span>
                </div>
                <ChevronDown v-if="orderingOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view store orders')"
                    href="/store-orders"
                    :icon="ShoppingCart"
                >
                    Store Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view store orders')"
                    href="/emergency-orders"
                    :icon="ShoppingCart"
                >
                    Emergency Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view store orders')"
                    href="/additional-orders"
                    :icon="ShoppingCart"
                >
                    Additional Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view dts orders')"
                    href="/dts-orders"
                    :icon="ShoppingBasket"
                >
                    DTS Orders
                </NavLink>
                <NavLink href="/direct-receiving" :icon="ShoppingBasket">
                    Direct Receiving
                </NavLink>
                <NavLink
                    v-if="hasAccess('view orders for approval list')"
                    href="/orders-approval"
                    :icon="SquareChartGantt"
                >
                    List of Orders (Created SO) form SM Approval
                </NavLink>
                <NavLink
                    v-if="hasAccess('view orders for cs approval list')"
                    href="/cs-approvals"
                    :icon="SquareChartGantt"
                >
                    CS Review List
                </NavLink>
                <NavLink
                    v-if="hasAccess('view orders for cs approval list')"
                    href="/additional-orders-approval"
                    :icon="SquareChartGantt"
                >
                    Additional Order Approval
                </NavLink>

                <NavLink
                    v-if="hasAccess('view orders for cs approval list')"
                    href="/emergency-orders-approval"
                    :icon="SquareChartGantt"
                >
                    Emergency Order Approval
                </NavLink>
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
                    <span>Receiving</span>
                </div>
                <ChevronDown v-if="receivingOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view approved orders')"
                    href="/orders-receiving"
                    :icon="ClipboardList"
                >
                    Approved Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view approved received items')"
                    href="/approved-orders"
                    :icon="FileCheck"
                >
                    Confirmed/Approved Received SO
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
                    <span>Sales</span>
                </div>
                <ChevronDown v-if="salesOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="false"
                    href="/sales-orders"
                    :icon="ChartColumnBig"
                >
                    Sales Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view store transactions')"
                    href="/store-transactions/summary"
                    :icon="ArrowLeftRight"
                >
                    Store Transactions
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
                    <span>Inventory</span>
                </div>
                <ChevronDown v-if="inventoryOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('view items list')"
                    href="/items-list"
                    :icon="PackageSearch"
                >
                    NN Inventory Items
                </NavLink>
                <NavLink
                    v-if="hasAccess('view items list')"
                    href="/sapitems-list"
                    :icon="TextSelect"
                >
                    SAP Mastlist Items
                </NavLink>
                <NavLink
                    v-if="hasAccess('view items list')"
                    href="/SupplierItems-list"
                    :icon="Warehouse"
                >
                    Supplier Items
                </NavLink>
                <NavLink
                    v-if="hasAccess('view items list')"
                    href="/POSMasterfile-list"
                    :icon="TextSelect"
                >
                    POS Masterlist
                </NavLink>
                <NavLink
                    v-if="hasAccess('view bom list')"
                    href="/menu-list"
                    :icon="Scroll"
                >
                    BOM
                </NavLink>
                <NavLink
                    v-if="hasAccess('view stock management')"
                    href="/stock-management"
                    :icon="FolderKanban"
                >
                    Stock Management
                </NavLink>
                <NavLink
                    v-if="hasAccess('view stock management')"
                    href="/soh-adjustment"
                    :icon="FolderKanban"
                >
                    SOH Adjustment
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
                    <span>Reports</span>
                </div>
                <ChevronDown v-if="reportsOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink href="/top-10-inventories" :icon="List">
                    Top 10 Inventories
                </NavLink>
                <NavLink href="/days-inventory-outstanding" :icon="List">
                    Days Inventory Outstanding
                </NavLink>
                <NavLink href="/days-payable-outstanding" :icon="List">
                    Days Payable Outstanding
                </NavLink>
                <NavLink href="/sales-report" :icon="List">
                    Sales Report
                </NavLink>
                <NavLink href="/inventories-report" :icon="List">
                    Inventories Report
                </NavLink>
                <NavLink href="/upcoming-inventories" :icon="List">
                    Upcoming Inventories
                </NavLink>
                <NavLink href="/account-payable" :icon="List">
                    Account Payable
                </NavLink>
                <NavLink href="/cost-of-goods" :icon="List">
                    Cost Of Goods
                </NavLink>
                <NavLink
                    v-if="hasAccess('view items order summary')"
                    href="/product-orders-summary"
                    :icon="List"
                >
                    Item Orders Summary
                </NavLink>
                <NavLink
                    v-if="hasAccess('view ice cream orders')"
                    href="/ice-cream-orders"
                    :icon="IceCreamCone"
                >
                    Ice Cream Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view salmon orders')"
                    href="/salmon-orders"
                    :icon="FishSymbol"
                >
                    Salmon Orders
                </NavLink>
                <NavLink
                    v-if="hasAccess('view fruits and vegetables orders')"
                    href="/fruits-and-vegetables"
                    :icon="Vegan"
                >
                    Fruits And Vegetables Orders
                </NavLink>
            </CollapsibleContent>
        </Collapsible>

        <!-- Reference Section -->
        <Collapsible
            v-if="canViewReferenceGroup"
            v-model:open="referenceOpen"
            class="w-full"
        >
            <CollapsibleTrigger
                class="flex items-center justify-between w-full py-2 hover:bg-muted/50 rounded-md px-2"
            >
                <div class="flex items-center">
                    <span>Reference</span>
                </div>
                <ChevronDown v-if="referenceOpen" class="h-4 w-4" />
                <ChevronRight v-else class="h-4 w-4" />
            </CollapsibleTrigger>
            <CollapsibleContent class="pl-2">
                <NavLink
                    v-if="hasAccess('manage references')"
                    href="/category-list"
                    :icon="FolderDot"
                >
                    Categories
                </NavLink>
                <NavLink href="/wip-list" :icon="FolderDot"> WIP List </NavLink>
                <NavLink
                    v-if="hasAccess('manage references')"
                    href="/menu-categories"
                    :icon="FileSliders"
                >
                    Menu Categories
                </NavLink>
                <NavLink href="/uom-conversions" :icon="FileSliders">
                    UOM Conversions
                </NavLink>
                <NavLink
                    v-if="hasAccess('manage references')"
                    href="/inventory-categories"
                    :icon="LayoutList"
                >
                    Invetory Categories
                </NavLink>
                <NavLink
                    v-if="hasAccess('manage references')"
                    href="/unit-of-measurements"
                    :icon="LayoutList"
                >
                    Unit of Measurements
                </NavLink>
                <NavLink
                    v-if="hasAccess('manage references')"
                    href="/store-branches"
                    :icon="AppWindowMac"
                >
                    Store Branches
                </NavLink>
                <NavLink
                    v-if="hasAccess('manage references')"
                    href="/suppliers"
                    :icon="Warehouse"
                >
                    Suppliers
                </NavLink>
                <NavLink
                    v-if="hasAccess('manage references')"
                    href="/cost-centers"
                    :icon="TextSelect"
                >
                    Cost Centers
                </NavLink>
            </CollapsibleContent>
        </Collapsible>
    </nav>
</template>
