<script setup>
import Logo from "../../images/temporaryLoginImage.png";
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
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
import Toast from "primevue/toast";
import { router } from "@inertiajs/vue3";
import ConfirmDialog from "primevue/confirmdialog";
import { ref, onMounted, onUnmounted } from 'vue';

const props = defineProps({
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
    hasExcelDownload: {
        type: Boolean,
        default: false,
        required: false,
    },
    exportRoute: {
        type: String,
        required: false,
    },
    pdfRoute: {
        type: String,
        required: false,
    },
});

import Sidebar from "@/Components/Sidebar.vue";

const logout = () => {
    router.post("/logout");
};

const exportExcel = () => {
    window.open(props.exportRoute, "_blank");
};

const exportPdf = () => {
    window.open(props.pdfRoute, "_blank");
};

// Custom mobile sidebar state management
const isMobileSidebarOpen = ref(false);

const openMobileSidebar = () => {
    isMobileSidebarOpen.value = true;
    // Prevent body scroll when sidebar is open
    document.body.style.overflow = 'hidden';
};

const closeMobileSidebar = () => {
    isMobileSidebarOpen.value = false;
    // Restore body scroll
    document.body.style.overflow = '';
};

const handleEscapeKey = (event) => {
    if (event.key === 'Escape' && isMobileSidebarOpen.value) {
        closeMobileSidebar();
    }
};

const handleBackdropClick = (event) => {
    if (event.target === event.currentTarget) {
        closeMobileSidebar();
    }
};

// Add and remove event listeners
onMounted(() => {
    document.addEventListener('keydown', handleEscapeKey);
});

onUnmounted(() => {
    document.removeEventListener('keydown', handleEscapeKey);
    // Restore body scroll in case component is unmounted while sidebar is open
    document.body.style.overflow = '';
});
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
                        <img :src="Logo" alt="logo" class="size-52 -ml-7" />
                    </a>
                </div>
                <div
                    class="flex-1 overflow-y-auto scrollbar-thin scrollbar-track-gray-100 scrollbar-thumb-gray-300 hover:scrollbar-thumb-gray-400"
                >
                    <!-- Desktop Sidebar -->
                    <Sidebar />
                </div>
            </div>
        </div>

        <div class="flex flex-col overflow-hidden min-h-screen max-h-screen">
            <header
                class="flex-shrink-0 flex h-14 items-center gap-4 border-b bg-muted/40 px-4 lg:h-[60px] lg:px-6"
            >
                <!-- Custom Mobile Menu Button -->
                <Button
                    variant="outline"
                    size="icon"
                    class="shrink-0 md:hidden"
                    @click="openMobileSidebar"
                >
                    <Menu class="h-5 w-5" />
                    <span class="sr-only">Toggle navigation menu</span>
                </Button>

                <!-- Custom Mobile Sidebar -->
                <div
                    v-if="isMobileSidebarOpen"
                    class="fixed inset-0 z-50 flex md:hidden"
                    @click="handleBackdropClick"
                >
                    <!-- Backdrop -->
                    <div class="fixed inset-0 bg-black/50 backdrop-blur-sm"></div>

                    <!-- Mobile Sidebar Content -->
                    <div class="relative flex flex-col w-80 h-full bg-white border-r shadow-xl">
                        <!-- Sidebar Header with Close Button -->
                        <div class="flex items-center justify-between p-4 border-b bg-muted/40">
                            <a href="/" class="flex items-center font-semibold">
                                <img :src="Logo" alt="logo" class="h-12" />
                            </a>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="closeMobileSidebar"
                                class="h-8 w-8 p-0 hover:bg-gray-100"
                            >
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                            </Button>
                        </div>

                        <!-- Mobile Sidebar Navigation -->
                        <div class="flex-1 overflow-y-auto p-4">
                            <Sidebar />
                        </div>
                    </div>
                </div>

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
                <div class="flex items-center justify-between gap-2">
                    <h1 class="text-lg font-semibold md:text-2xl">
                        {{ heading }}
                    </h1>
                    <DivFlexCenter class="gap-3">
                        <!-- New slot for header actions -->
                        <slot name="header-actions"></slot> 
                        <Button
                            v-show="pdfRoute"
                            @click="exportPdf"
                            class="sm:text-normal text-xs"
                            >Export to Pdf</Button
                        >
                        <Button
                            v-show="hasExcelDownload"
                            @click="exportExcel"
                            class="sm:text-normal text-xs"
                            >Export to Excel</Button
                        >
                        <Button
                            class="sm:text-normal text-xs"
                            v-show="hasButton"
                            @click="handleClick"
                            >{{ buttonName }}</Button
                        >
                    </DivFlexCenter>
                </div>
                <div class="space-y-5">
                    <!-- MainView -->
                    <slot />
                </div>
            </main>
        </div>
    </div>
</template>
