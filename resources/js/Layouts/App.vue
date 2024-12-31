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

const isAdmin = is_admin;

const canViewOrderingGroup = is_admin || roles.includes("so encoder");
const canViewReceivingGroup = is_admin || roles.includes("rec approver");
const canViewSalesGroup = is_admin;
const canViewReportsGroup = is_admin || roles.includes("so encoder");
const canViewInventoryGroup = is_admin;

const canViewStoreOrderPage = is_admin || roles.includes("so encoder");
const canViewOrderApprovals = is_admin;

const canViewReceivingOrders = is_admin;
const canViewReceivingApprovals = is_admin || roles.includes("rec approver");
const canViewApprovedReceivedItems = is_admin;

const canViewItems = is_admin;

const canViewItemsOrderSummary = is_admin;

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
                    <Button
                        variant="outline"
                        size="icon"
                        class="ml-auto h-8 w-8"
                    >
                        <Bell class="h-4 w-4" />
                        <span class="sr-only">Toggle notifications</span>
                    </Button>
                </div>
                <div
                    class="flex-1 overflow-y-auto scrollbar-thin scrollbar-track-gray-100 scrollbar-thumb-gray-300 hover:scrollbar-thumb-gray-400"
                >
                    <nav class="grid items-start pl-4 text-sm font-medium">
                        <NavLink href="/dashboard" :icon="Home">
                            Dashboard
                        </NavLink>
                        <NavLink href="/audits" :icon="MonitorCog" v-if="false">
                            Audits
                        </NavLink>
                        <DropdownMenuLabel v-if="isAdmin">
                            Schedules
                        </DropdownMenuLabel>
                        <NavLink
                            v-if="isAdmin"
                            href="/delivery-schedules"
                            :icon="ShoppingCart"
                        >
                            DTS Delivery Schedules
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
                            v-if="canViewStoreOrderPage"
                            href="/dts-orders"
                            :icon="ShoppingCart"
                        >
                            DTS Orders
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
                            Orders
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
                            v-if="true"
                            href="/product-sales"
                            :icon="FileCheck"
                        >
                            Product Sales
                        </NavLink>
                        <NavLink
                            v-if="true"
                            href="/sales-orders"
                            :icon="FileCheck"
                        >
                            Sales Order
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
                        <NavLink
                            v-if="canViewItems"
                            href="/usage-records"
                            :icon="PackageSearch"
                        >
                            Usage Records
                        </NavLink>
                        <NavLink
                            v-if="canViewItems"
                            href="/stock-management"
                            :icon="PackageSearch"
                        >
                            Stock Management
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
                            v-if="canViewApprovedReceivedItems"
                            href="/ice-cream-orders"
                            :icon="FileCheck"
                        >
                            Ice Cream Orders
                        </NavLink>
                        <NavLink
                            v-if="canViewApprovedReceivedItems"
                            href="/salmon-orders"
                            :icon="FileCheck"
                        >
                            Salmon Orders
                        </NavLink>
                        <NavLink
                            v-if="canViewApprovedReceivedItems"
                            href="/fruits-and-vegetables"
                            :icon="FileCheck"
                        >
                            Fruits And Vegetables Orders
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
                                    Orders
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
                                    v-if="true"
                                    href="/product-sales"
                                    :icon="FileCheck"
                                >
                                    Product Sales
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
                        <DropdownMenuItem>Settings</DropdownMenuItem>
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
