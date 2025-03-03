<script setup>
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
} from "@/components/ui/dropdown-menu";
import NavLink from "./NavLink.vue";
import { usePage } from "@inertiajs/vue3";
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
        <NavLink href="/roles" :icon="FileCog" v-if="hasAccess('view roles')">
            Roles
        </NavLink>
        <NavLink href="/audits" :icon="MonitorCog" v-if="false">
            Audits
        </NavLink>
        <DropdownMenuLabel v-if="isAdmin"> Schedules </DropdownMenuLabel>
        <NavLink
            v-if="hasAccess('view dts delivery schedules')"
            href="/delivery-schedules"
            :icon="CalendarCheck2"
        >
            DTS Delivery Schedules
        </NavLink>
        <DropdownMenuLabel v-if="canViewOrderingGroup">
            Ordering
        </DropdownMenuLabel>
        <NavLink
            v-if="hasAccess('view store orders')"
            href="/store-orders"
            :icon="ShoppingCart"
        >
            Store Orders
        </NavLink>
        <NavLink
            v-if="hasAccess('view dts orders')"
            href="/dts-orders"
            :icon="ShoppingBasket"
        >
            DTS Orders
        </NavLink>
        <NavLink href="cash-pull-out/" :icon="ShoppingBasket">
            Cash Pull Out
        </NavLink>
        <NavLink
            v-if="hasAccess('view orders for approval list')"
            href="/orders-approval"
            :icon="SquareChartGantt"
            s
        >
            Orders Approval
        </NavLink>
        <NavLink
            v-if="hasAccess('view orders for cs approval list')"
            href="/cs-approvals"
            :icon="SquareChartGantt"
        >
            CS Review List
        </NavLink>
        <DropdownMenuLabel v-if="canViewReceivingGroup">
            Receiving
        </DropdownMenuLabel>
        <NavLink
            v-if="hasAccess('view approved orders')"
            href="/orders-receiving"
            :icon="ClipboardList"
        >
            Approved Orders
        </NavLink>
        <NavLink
            v-if="hasAccess('view received orders for approval list')"
            href="/receiving-approvals"
            :icon="ClipboardCheck"
        >
            Approvals
        </NavLink>
        <NavLink
            v-if="hasAccess('view approved received items')"
            href="/approved-orders"
            :icon="FileCheck"
        >
            Approved Received Items
        </NavLink>
        <DropdownMenuLabel v-if="canViewSalesGroup"> Sales </DropdownMenuLabel>
        <!-- <NavLink
                            v-if="true"
                            href="/product-sales"
                            :icon="ChartColumnBig"
                        >
                            Product Sales
                        </NavLink> -->
        <NavLink v-if="false" href="/sales-orders" :icon="ChartColumnBig">
            Sales Orders
        </NavLink>
        <NavLink
            v-if="hasAccess('view store transactions')"
            href="/store-transactions/summary"
            :icon="ArrowLeftRight"
        >
            Store Transactions
        </NavLink>
        <DropdownMenuLabel v-if="canViewInventoryGroup">
            Inventory
        </DropdownMenuLabel>
        <NavLink
            v-if="hasAccess('view items list')"
            href="/items-list"
            :icon="PackageSearch"
        >
            Items
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
        <DropdownMenuLabel v-if="canViewReportsGroup">
            Reports
        </DropdownMenuLabel>
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
        <DropdownMenuLabel v-if="canViewReferenceGroup">
            Reference
        </DropdownMenuLabel>
        <NavLink
            v-if="hasAccess('manage references')"
            href="/category-list"
            :icon="FolderDot"
        >
            Categories
        </NavLink>
        <NavLink
            v-if="hasAccess('manage references')"
            href="/menu-categories"
            :icon="FileSliders"
        >
            Menu Categories
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
    </nav>
</template>
