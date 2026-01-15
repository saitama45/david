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
    BookOpen,
} from "lucide-vue-next";
import Toast from "primevue/toast";
import { router, usePage } from "@inertiajs/vue3";
import ConfirmDialog from "primevue/confirmdialog";
import PrimeDialog from "primevue/dialog";
import { ref, reactive, onMounted, onUnmounted, computed, watch } from 'vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

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

const isSalesReminderModalOpen = ref(false);

const openSalesReminderModal = () => {
    isSalesReminderModalOpen.value = true;
};

const groupedMissingSales = computed(() => {
    const details = page.props.notifications?.salesUploadMissingDetails || [];
    const groups = {};
    
    details.forEach(item => {
        if (!groups[item.branch]) {
            groups[item.branch] = [];
        }
        groups[item.branch].push(item);
    });
    
    // Sort keys (branches) alphabetically if needed, usually iteration order follows insertion for string keys mostly
    return groups;
});

const openBranches = reactive({});

const toggleBranch = (branch) => {
    openBranches[branch] = !openBranches[branch];
};

watch(() => groupedMissingSales.value, (newVal) => {
    const keys = Object.keys(newVal);
    // If only one branch, open it by default. Otherwise, keep collapsed or could open first.
    if (keys.length === 1) {
        openBranches[keys[0]] = true;
    }
}, { immediate: true });
</script>

<template>
    <Toast />
    <ConfirmDialog></ConfirmDialog>
    <PrimeDialog v-model:visible="isSalesReminderModalOpen" modal header="Sales Upload Reminder" :style="{ width: '50rem' }" :breakpoints="{ '960px': '75vw', '641px': '90vw' }">
        <div class="py-2">
            <p class="mb-4 text-sm text-gray-600">
                The following branches have missing sales transactions for the indicated dates **within the last 30 days**. Please upload them promptly to ensure accurate inventory tracking.
            </p>
            
            <div class="border rounded-lg overflow-hidden max-h-[60vh] overflow-y-auto bg-white">
                <div v-for="(items, branch) in groupedMissingSales" :key="branch" class="border-b last:border-b-0">
                    <div 
                        class="bg-gray-50 px-4 py-3 flex justify-between items-center cursor-pointer hover:bg-gray-100 transition-colors select-none"
                        @click="toggleBranch(branch)"
                    >
                        <div class="flex items-center gap-2">
                            <ChevronDown v-if="openBranches[branch]" class="h-4 w-4 text-gray-500" />
                            <ChevronRight v-else class="h-4 w-4 text-gray-500" />
                            <span class="font-semibold text-gray-800">{{ branch }}</span>
                        </div>
                        <span class="bg-red-100 text-red-700 text-xs font-bold px-2 py-0.5 rounded-full">{{ items.length }} missing</span>
                    </div>
                    <div v-if="openBranches[branch]" class="divide-y border-t">
                        <div v-for="(item, index) in items" :key="index" class="px-4 py-2 flex justify-between items-center hover:bg-gray-50 transition-colors pl-10">
                            <span class="text-sm text-gray-600 flex items-center gap-2">
                                📅 {{ item.date }}
                            </span>
                            <Button 
                                size="sm" 
                                variant="outline" 
                                class="h-7 text-xs px-3 gap-2" 
                                @click="router.visit(route('store-transactions.index', { order_date: item.raw_date }))"
                            >
                                Upload
                                <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="lucide lucide-arrow-right"><path d="M5 12h14"/><path d="m12 5 7 7-7 7"/></svg>
                            </Button>
                        </div>
                    </div>
                </div>
                
                <div v-if="Object.keys(groupedMissingSales).length === 0" class="p-8 text-center text-gray-500">
                    No missing sales found.
                </div>
            </div>
        </div>
        <template #footer>
            <Button variant="secondary" @click="isSalesReminderModalOpen = false">Close</Button>
        </template>
    </PrimeDialog>
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
                <Link href="/knowledge-base" class="inline-flex items-center justify-center whitespace-nowrap rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 h-9 px-4 py-2 bg-secondary text-secondary-foreground shadow-sm hover:bg-secondary/80">
                    <BookOpen class="h-4 w-4 mr-2" />
                    Knowledge Base
                </Link>
                
                <!-- Notification Bell -->
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="ghost" size="icon" class="relative rounded-full">
                            <Bell class="h-7 w-7" />
                            <span v-if="(page.props.notifications?.massOrdersApprovalCount + page.props.notifications?.csMassCommitsCount + page.props.notifications?.csDtsMassCommitsCount + page.props.notifications?.intercoApprovalCount + page.props.notifications?.storeCommitsCount + page.props.notifications?.wastageLvl1Count + page.props.notifications?.wastageLvl2Count + page.props.notifications?.monthEndLvl1Count + page.props.notifications?.monthEndLvl2Count + (page.props.notifications?.orderReceivingCount || 0) + (page.props.notifications?.salesUploadReminderCount || 0)) > 0" 
                                  class="absolute -top-2 -right-2 flex h-6 w-6 items-center justify-center rounded-full bg-red-600 p-1 text-sm font-bold text-white">
                                {{ (page.props.notifications?.massOrdersApprovalCount || 0) + (page.props.notifications?.csMassCommitsCount || 0) + (page.props.notifications?.csDtsMassCommitsCount || 0) + (page.props.notifications?.intercoApprovalCount || 0) + (page.props.notifications?.storeCommitsCount || 0) + (page.props.notifications?.wastageLvl1Count || 0) + (page.props.notifications?.wastageLvl2Count || 0) + (page.props.notifications?.monthEndLvl1Count || 0) + (page.props.notifications?.monthEndLvl2Count || 0) + (page.props.notifications?.orderReceivingCount || 0) + (page.props.notifications?.salesUploadReminderCount || 0) }}
                            </span>
                            <span class="sr-only">Notifications</span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="w-80 max-h-96 overflow-y-auto">
                        <div class="sticky top-0 bg-white border-b px-4 py-3 z-10">
                            <h2 class="font-semibold text-sm text-gray-900">Notifications</h2>
                            <p class="text-xs text-gray-500 mt-1">{{ (page.props.notifications?.massOrdersApprovalCount || 0) + (page.props.notifications?.csMassCommitsCount || 0) + (page.props.notifications?.csDtsMassCommitsCount || 0) + (page.props.notifications?.intercoApprovalCount || 0) + (page.props.notifications?.storeCommitsCount || 0) + (page.props.notifications?.wastageLvl1Count || 0) + (page.props.notifications?.wastageLvl2Count || 0) + (page.props.notifications?.monthEndLvl1Count || 0) + (page.props.notifications?.monthEndLvl2Count || 0) + (page.props.notifications?.orderReceivingCount || 0) + (page.props.notifications?.salesUploadReminderCount || 0) }} pending item(s)</p>
                        </div>
                        <div v-if="(page.props.notifications?.massOrdersApprovalCount + page.props.notifications?.csMassCommitsCount + page.props.notifications?.csDtsMassCommitsCount + page.props.notifications?.intercoApprovalCount + page.props.notifications?.storeCommitsCount + page.props.notifications?.wastageLvl1Count + page.props.notifications?.wastageLvl2Count + page.props.notifications?.monthEndLvl1Count + page.props.notifications?.monthEndLvl2Count + (page.props.notifications?.orderReceivingCount || 0) + (page.props.notifications?.salesUploadReminderCount || 0)) > 0" class="divide-y pr-2">
                            <DropdownMenuItem v-if="page.props.notifications?.orderReceivingCount > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('orders-receiving.index', { currentFilter: 'commited' }))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-blue-400 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Order Receiving</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-blue-600">{{ page.props.notifications.orderReceivingCount }}</span> orders to receive</p>
                                    <div v-if="page.props.notifications?.orderReceivingDates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.orderReceivingDates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.orderReceivingDates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.orderReceivingDates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.salesUploadReminderCount > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="openSalesReminderModal">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-pink-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Sales Upload Reminder</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-pink-600">{{ page.props.notifications.salesUploadReminderCount }}</span> branch(es) missing sales for yesterday</p>
                                    <p class="text-xs text-blue-500 mt-1">Click to view details</p>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.massOrdersApprovalCount > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('mass-orders-approval.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-red-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Mass Orders Approval</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-red-600">{{ page.props.notifications.massOrdersApprovalCount }}</span> pending orders</p>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.csMassCommitsCount > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('cs-mass-commits.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-orange-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">CS Mass Commits</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-orange-600">{{ page.props.notifications.csMassCommitsCount }}</span> orders to commit</p>
                                    <div v-if="page.props.notifications?.csMassCommitsDates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.csMassCommitsDates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.csMassCommitsDates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.csMassCommitsDates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.csDtsMassCommitsCount > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('cs-dts-mass-commits.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-yellow-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">CS DTS Mass Commits</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-yellow-600">{{ page.props.notifications.csDtsMassCommitsCount }}</span> batch(es) to commit</p>
                                    <div v-if="page.props.notifications?.csDtsMassCommitsBatches?.length" class="mt-1 space-y-0.5">
                                        <span v-for="batch in page.props.notifications.csDtsMassCommitsBatches.slice(0, 2)" :key="batch" class="text-xs text-gray-500 block">📦 {{ batch }}</span>
                                        <span v-if="page.props.notifications.csDtsMassCommitsBatches.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.csDtsMassCommitsBatches.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.intercoApprovalCount > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('interco-approval.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-blue-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Interco Approvals</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-blue-600">{{ page.props.notifications.intercoApprovalCount }}</span> order(s) to approve</p>
                                    <div v-if="page.props.notifications?.intercoApprovalDates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.intercoApprovalDates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.intercoApprovalDates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.intercoApprovalDates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.storeCommitsCount > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('store-commits.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-green-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Store Commits</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-green-600">{{ page.props.notifications.storeCommitsCount }}</span> order(s) to commit</p>
                                    <div v-if="page.props.notifications?.storeCommitsDates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.storeCommitsDates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.storeCommitsDates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.storeCommitsDates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.wastageLvl1Count > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('wastage-approval-lvl1.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-purple-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Wastage Approval Lvl 1</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-purple-600">{{ page.props.notifications.wastageLvl1Count }}</span> wastage(s) to approve</p>
                                    <div v-if="page.props.notifications?.wastageLvl1Dates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.wastageLvl1Dates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.wastageLvl1Dates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.wastageLvl1Dates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.wastageLvl2Count > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('wastage-approval-lvl2.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-indigo-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Wastage Approval Lvl 2</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-indigo-600">{{ page.props.notifications.wastageLvl2Count }}</span> wastage(s) to approve</p>
                                    <div v-if="page.props.notifications?.wastageLvl2Dates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.wastageLvl2Dates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.wastageLvl2Dates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.wastageLvl2Dates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.monthEndLvl1Count > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('month-end-count-approvals.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-cyan-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Month End Approval Lvl 1</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-cyan-600">{{ page.props.notifications.monthEndLvl1Count }}</span> record(s) to approve</p>
                                    <div v-if="page.props.notifications?.monthEndLvl1Dates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.monthEndLvl1Dates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.monthEndLvl1Dates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.monthEndLvl1Dates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                            <DropdownMenuItem v-if="page.props.notifications?.monthEndLvl2Count > 0" class="cursor-pointer px-4 py-3 hover:bg-gray-50 flex items-start gap-3" @click="router.visit(route('month-end-count-approvals-level2.index'))">
                                <div class="flex-shrink-0 w-2 h-2 rounded-full bg-teal-500 mt-1.5"></div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900">Month End Approval Lvl 2</p>
                                    <p class="text-xs text-gray-600 mt-0.5"><span class="font-bold text-teal-600">{{ page.props.notifications.monthEndLvl2Count }}</span> record(s) to approve</p>
                                    <div v-if="page.props.notifications?.monthEndLvl2Dates?.length" class="mt-1 space-y-0.5">
                                        <span v-for="date in page.props.notifications.monthEndLvl2Dates.slice(0, 2)" :key="date" class="text-xs text-gray-500 block">📅 {{ date }}</span>
                                        <span v-if="page.props.notifications.monthEndLvl2Dates.length > 2" class="text-xs text-gray-400">+{{ page.props.notifications.monthEndLvl2Dates.length - 2 }} more</span>
                                    </div>
                                </div>
                            </DropdownMenuItem>
                        </div>
                        <div v-else class="p-6 text-center">
                            <div class="text-gray-400 mb-2">🔔</div>
                            <p class="text-sm text-gray-600">No new notifications</p>
                        </div>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span class="text-sm font-medium">{{ user?.first_name }}</span>
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
