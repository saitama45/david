<script setup>
import { Button } from "@/components/ui/button";
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
    Package,
    Package2,
    ShoppingCart,
    SquareChartGantt,
    Folders,
    FileCheck,
    PackageSearch,
    ScrollText,
    LayoutList,
    Store,
    Container,
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
const isActive = (route) => {
    return usePage().url.includes(route);
};

const logout = () => {
    router.post("/logout");
};
</script>

<template>
    <Toast />
    <ConfirmDialog></ConfirmDialog>
    <div
        class="grid min-h-screen w-full md:grid-cols-[220px_1fr] lg:grid-cols-[280px_1fr]"
    >
        <div class="hidden border-r bg-muted/40 md:block">
            <div class="flex h-full max-h-screen flex-col gap-2">
                <div
                    class="flex h-14 items-center border-b px-4 lg:h-[60px] lg:px-6"
                >
                    <a href="/" class="flex items-center gap-2 font-semibold">
                        <Package2 class="h-6 w-6" />
                        <span class="">Project Story</span>
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
                <div class="flex-1">
                    <nav class="grid items-start pl-4 text-sm font-medium">
                        <NavLink href="/dashboard" :icon="Home">
                            Dashboard
                        </NavLink>
                        <DropdownMenuLabel> Ordering </DropdownMenuLabel>
                        <NavLink href="/store-orders" :icon="ShoppingCart">
                            Store Orders
                        </NavLink>
                        <NavLink
                            href="/orders-approval"
                            :icon="SquareChartGantt"
                        >
                            Orders Approval
                        </NavLink>
                        <DropdownMenuLabel> Receiving </DropdownMenuLabel>
                        <NavLink href="/orders-receiving" :icon="Folders">
                            Orders
                        </NavLink>
                        <NavLink href="/approved-orders" :icon="FileCheck">
                            Approved Orders
                        </NavLink>
                        <DropdownMenuLabel> Sales </DropdownMenuLabel>
                        <DropdownMenuLabel> Inventory </DropdownMenuLabel>
                        <NavLink href="/items-list" :icon="PackageSearch">
                            Items
                        </NavLink>
                        <DropdownMenuLabel> Reports </DropdownMenuLabel>
                        <DropdownMenuLabel> Reference </DropdownMenuLabel>
                        <NavLink href="/category-list" :icon="ScrollText">
                            Categories
                        </NavLink>
                        <NavLink
                            href="/inventory-categories"
                            :icon="LayoutList"
                        >
                            Invetory Categories
                        </NavLink>
                        <NavLink href="/store-branches" :icon="Store">
                            Store Branches
                        </NavLink>
                        <NavLink href="/suppliers" :icon="Container">
                            Suppliers
                        </NavLink>
                        <DropdownMenuLabel> User </DropdownMenuLabel>
                        <NavLink href="/users" :icon="UsersRound">
                            Users
                        </NavLink>
                    </nav>
                </div>
                <div class="mt-auto p-4">Test</div>
            </div>
        </div>
        <div class="flex flex-col">
            <header
                class="flex h-14 items-center gap-4 border-b bg-muted/40 px-4 lg:h-[60px] lg:px-6"
            >
                <Sheet>
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
                    <SheetContent side="left" class="flex flex-col">
                        <nav class="grid gap-2 text-lg font-medium">
                            <a
                                href="#"
                                class="flex items-center gap-2 text-lg font-semibold"
                            >
                                <Package2 class="h-6 w-6" />
                                <span class="sr-only">Acme Inc</span>
                            </a>
                            <a
                                href="#"
                                class="mx-[-0.65rem] flex items-center gap-4 rounded-xl px-3 py-2 text-muted-foreground hover:text-foreground"
                            >
                                <Home class="h-5 w-5" />
                                Dashboard
                            </a>
                            <a
                                href="#"
                                class="mx-[-0.65rem] flex items-center gap-4 rounded-xl px-3 py-2 text-muted-foreground hover:text-foreground"
                            >
                                <Home class="h-5 w-5" />
                                Ordering
                            </a>
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
                        <DropdownMenuLabel>My Account</DropdownMenuLabel>
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
                class="flex flex-1 flex-col gap-4 p-4 lg:gap-6 lg:p-6 bg-white/10"
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
