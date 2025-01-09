<script setup>
import Logo from "../../images/logo.png";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetTrigger } from "@/components/ui/sheet";
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
} from "lucide-vue-next";
import Toast from "primevue/toast";
import { router } from "@inertiajs/vue3";
import ConfirmDialog from "primevue/confirmdialog";

import NavLink from "../Components/NavLink.vue";

defineProps({
    heading: String,
    handleClick: {
        type: Function,
        required: false,
    },
    hasButton: {
        type: Boolean,
        default: false,
        required: false,
    },
    buttonName: {
        type: String,
        required: false,
    },
});
import { usePage } from "@inertiajs/vue3";

const { roles, is_admin } = usePage().props.auth;
const permissions = usePage().props.auth.permissions;
console.log(permissions);
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

const canViewStoreOrderPage = is_admin || roles.includes("so encoder");
const canViewOrderApprovals = is_admin || roles.includes("rec approver");

const canViewReceivingOrders = is_admin || roles.includes("so encoder");
const canViewReceivingApprovals = is_admin || roles.includes("rec approver");
const canViewApprovedReceivedItems = is_admin || roles.includes("so encoder");

const canViewDtsOrdersSummary = is_admin || roles.includes("rec approver");

const canViewItems = is_admin;

const canViewItemsOrderSummary = is_admin;
const canViewStockManagement = is_admin || roles.includes("so encoder");
const canViewStocks = is_admin || roles.includes("so encoder");

const canViewCategories = is_admin;
const canViewInventoryCategories = is_admin;
const canViewStoreBranch = is_admin;
const canViewSupplier = is_admin;

const canViewUsers = is_admin;

const logout = () => {
    router.post("/logout");
};
</script>

<template>
    <Toast />
    <ConfirmDialog></ConfirmDialog>
    <div
        class="grid min-h-screen max-h-screen w-full md:grid-cols-[220px_1fr] lg:grid-cols-[280px_1fr] overflow-hidden"
    >
        <div class="hidden border-r bg-muted/40 md:block overflow-hidden">
            <div class="flex h-full max-h-screen flex-col gap-2">
                <div
                    class="flex h-14 items-center border-b px-4 lg:h-[60px] lg:px-6"
                >
                    <a href="/" class="flex items-center font-semibold">
                        <img :src="Logo" alt="logo" class="size-20" />
                        <span class="font-bold">DAVID</span>
                    </a>
                    <!-- <Button
                        variant="outline"
                        size="icon"
                        class="ml-auto h-8 w-8"
                    >
                        <Bell class="h-4 w-4" />
                        <span class="sr-only">Toggle notifications</span>
                    </Button> -->
                </div>
                <div
                    class="flex-1 overflow-y-auto scrollbar-thin scrollbar-track-gray-100 scrollbar-thumb-gray-300 hover:scrollbar-thumb-gray-400"
                >
                    <nav class="grid items-start pl-4 text-sm font-medium">
                        <NavLink href="/dashboard" :icon="Home">
                            Dashboard
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view users')"
                            href="/users"
                            :icon="UsersRound"
                        >
                            Users
                        </NavLink>
                        <NavLink
                            href="/roles"
                            :icon="FileCog"
                            v-if="hasAccess('view roles')"
                        >
                            Roles
                        </NavLink>
                        <NavLink href="/audits" :icon="MonitorCog" v-if="false">
                            Audits
                        </NavLink>
                        <DropdownMenuLabel v-if="isAdmin">
                            Schedules
                        </DropdownMenuLabel>
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
                        <NavLink
                            v-if="hasAccess('view orders for approval list')"
                            href="/orders-approval"
                            :icon="SquareChartGantt"
                        >
                            Orders Approval
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
                            v-if="
                                hasAccess(
                                    'view received orders for approval list'
                                )
                            "
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
                        <DropdownMenuLabel v-if="canViewSalesGroup">
                            Sales
                        </DropdownMenuLabel>
                        <!-- <NavLink
                            v-if="true"
                            href="/product-sales"
                            :icon="ChartColumnBig"
                        >
                            Product Sales
                        </NavLink> -->
                        <NavLink
                            v-if="true"
                            href="/sales-orders"
                            :icon="ChartColumnBig"
                        >
                            Sales Orders
                        </NavLink>
                        <NavLink
                            v-if="hasAccess('view store transactions')"
                            href="/usage-records"
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
                            v-if="hasAccess('view menu list')"
                            href="/menu-list"
                            :icon="Scroll"
                        >
                            Menu
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
                            v-if="
                                hasAccess('view fruits and vegetables orders')
                            "
                            href="/fruits-and-vegetables"
                            :icon="Vegan"
                        >
                            Fruits And Vegetables Orders
                        </NavLink>
                        <NavLink
                            href="/stocks"
                            :icon="ScanBarcode"
                            v-if="is_admin"
                        >
                            Stocks
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
                    </nav>
                </div>
            </div>
        </div>

        <div class="flex flex-col overflow-hidden min-h-screen max-h-screen">
            <header
                class="flex-shrink-0 flex h-14 items-center gap-4 border-b bg-muted/40 px-4 lg:h-[60px] lg:px-6"
            >
                <Sheet class="overflow-scroll">
                    <SheetTrigger as-child>
                        <Button
                            variant="outline"
                            size="icon"
                            class="shrink-0 md:hidden"
                        >
                            <Menu class="h-5 w-5" />
                            <span class="sr-only">Toggle navigation menu</span>
                        </Button>
                    </SheetTrigger>
                    <SheetContent
                        side="left"
                        class="flex flex-col overflow-scroll"
                    >
                        <nav
                            class="grid gap-2 text-lg font-medium overflow-scroll"
                        >
                            <!-- Logo -->
                            <a
                                href="#"
                                class="flex items-center gap-2 text-lg font-semibold"
                            >
                                <span class="text-sm">Project David</span>
                            </a>

                            <nav
                                class="grid items-start pl-4 text-sm font-medium"
                            >
                                <NavLink href="/dashboard" :icon="Home">
                                    Dashboard
                                </NavLink>
                                <DropdownMenuLabel v-if="canViewOrderingGroup">
                                    Ordering
                                </DropdownMenuLabel>
                                <NavLink
                                    v-if="canViewStoreOrderPage"
                                    href="/store-orders"
                                    :icon="ShoppingCart"
                                >
                                    Store Orders
                                </NavLink>
                                <NavLink
                                    v-if="canViewOrderApprovals"
                                    href="/orders-approval"
                                    :icon="SquareChartGantt"
                                >
                                    Orders Approval
                                </NavLink>
                                <DropdownMenuLabel v-if="canViewReceivingGroup">
                                    Receiving
                                </DropdownMenuLabel>
                                <NavLink
                                    v-if="canViewReceivingOrders"
                                    href="/orders-receiving"
                                    :icon="Folders"
                                >
                                    Approved Orders
                                </NavLink>
                                <NavLink
                                    v-if="canViewReceivingApprovals"
                                    href="/receiving-approvals"
                                    :icon="Folders"
                                >
                                    Approvals
                                </NavLink>
                                <NavLink
                                    v-if="canViewApprovedReceivedItems"
                                    href="/approved-orders"
                                    :icon="FileCheck"
                                >
                                    Approved Received Items
                                </NavLink>
                                <DropdownMenuLabel v-if="canViewSalesGroup">
                                    Sales
                                </DropdownMenuLabel>
                                <NavLink
                                    v-if="false"
                                    href="/product-sales"
                                    :icon="ChartColumnBig"
                                >
                                    Product Sales
                                </NavLink>
                                <NavLink
                                    v-if="true"
                                    href="/store-transactions"
                                    :icon="FileCheck"
                                >
                                    Store Transactions
                                </NavLink>
                                <NavLink
                                    v-if="true"
                                    href="/store-transactions"
                                    :icon="FileCheck"
                                >
                                    Store Transactions
                                </NavLink>
                                <DropdownMenuLabel v-if="canViewInventoryGroup">
                                    Inventory
                                </DropdownMenuLabel>
                                <NavLink
                                    v-if="canViewItems"
                                    href="/items-list"
                                    :icon="PackageSearch"
                                >
                                    Items
                                </NavLink>
                                <DropdownMenuLabel v-if="canViewReportsGroup">
                                    Reports
                                </DropdownMenuLabel>
                                <NavLink
                                    v-if="canViewItemsOrderSummary"
                                    href="/product-orders-summary"
                                    :icon="PackageSearch"
                                >
                                    Item Orders Summary
                                </NavLink>
                                <NavLink
                                    href="/stocks"
                                    :icon="PackageSearch"
                                    v-if="canViewStocks"
                                >
                                    Stocks
                                </NavLink>
                                <DropdownMenuLabel v-if="isAdmin">
                                    Reference
                                </DropdownMenuLabel>
                                <NavLink
                                    v-if="canViewCategories"
                                    href="/category-list"
                                    :icon="ScrollText"
                                >
                                    Categories
                                </NavLink>
                                <NavLink
                                    v-if="canViewInventoryCategories"
                                    href="/inventory-categories"
                                    :icon="LayoutList"
                                >
                                    Invetory Categories
                                </NavLink>
                                <NavLink
                                    v-if="canViewStoreBranch"
                                    href="/store-branches"
                                    :icon="Store"
                                >
                                    Store Branches
                                </NavLink>
                                <NavLink
                                    v-if="canViewSupplier"
                                    href="/suppliers"
                                    :icon="Container"
                                >
                                    Suppliers
                                </NavLink>
                                <DropdownMenuLabel v-if="isAdmin">
                                    User
                                </DropdownMenuLabel>
                                <NavLink
                                    v-if="canViewUsers"
                                    href="/users"
                                    :icon="UsersRound"
                                >
                                    Users
                                </NavLink>
                            </nav>
                        </nav>
                    </SheetContent>
                </Sheet>

                <div class="w-full flex-1">
                    <!-- <form>
                        <div class="relative">
                            <Search
                                class="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground"
                            />
                            <Input
                                type="search"
                                placeholder="Search products..."
                                class="w-full appearance-none bg-background pl-8 shadow-none md:w-2/3 lg:w-1/3"
                            />
                        </div>
                    </form> -->
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="secondary"
                            size="icon"
                            class="rounded-full"
                        >
                            <CircleUser class="h-5 w-5" />
                            <span class="sr-only">Toggle user menu</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem class="cursor-pointer">
                            <Link href="/profile">My Profile</Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem>Support</DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem>
                            <button @click="logout">Logout</button>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </header>
            <main
                class="flex-1 flex flex-col gap-4 p-4 lg:gap-6 lg:p-6 overflow-y-scroll scrollbar-thin scrollbar-track-gray-100 scrollbar-thumb-gray-300 hover:scrollbar-thumb-gray-400"
            >
                <div class="flex items-center justify-between">
                    <h1 class="text-lg font-semibold md:text-2xl">
                        {{ heading }}
                    </h1>
                    <Button v-show="hasButton" @click="handleClick">{{
                        buttonName
                    }}</Button>
                </div>
                <div class="space-y-5">
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
