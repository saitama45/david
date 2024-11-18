<script setup>
import Layout from "@/Layouts/App.vue";
import { Badge } from "@/components/ui/badge";
import { router } from "@inertiajs/vue3";
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from "@/components/ui/popover";

import { Filter, Eye } from "lucide-vue-next";
import { ref, watch } from "vue";
import { usePage } from "@inertiajs/vue3";
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { throttle } from "lodash";

const handleClick = () => {
    router.get("/store-orders/create");
};

const props = defineProps({
    orders: {
        type: Object,
    },
    branches: {
        type: Object,
    },
});

const showOrderDetails = (id) => {
    router.get(`/store-orders/show/${id}`);
};

let from = ref(usePage().props.from);
let to = ref(usePage().props.to);
let branchId = ref(usePage().props.branchId);
let search = ref(usePage().props.search);

watch(from, (value) => {
    router.get(
        route("store-orders.index"),
        { from: value, to: to.value },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(to, (value) => {
    router.get(
        route("store-orders.index"),
        { from: from.value, to: value },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(branchId, (value) => {
    router.get(
        route("store-orders.index"),
        { branchId: value },
        {
            preserveState: true,
            preserveScroll: true,
        }
    );
});

watch(
    search,
    throttle(function (value) {
        router.get(
            route("store-orders.index"),
            { search: value },
            {
                preserveState: true,
                replace: true,
            }
        );
    }, 500)
);

const statusBadgeColor = (status) => {
    switch (status) {
        case "RECEIVED":
            return "bg-green-500 text-white";
        case "PENDING":
            return "bg-yellow-500 text-white";
        case "INCOMPLETE":
            return "bg-orange-500 text-white";
        default:
            return "bg-yellow-500 text-white";
    }
};
const resetFilter = () => {
    (from.value = null),
        (to.value = null),
        (branchId.value = null),
        (search.value = null);
};
</script>

<template>
    <Layout
        heading="Store Orders"
        :hasButton="true"
        buttonName="Create New Order"
        :handleClick="handleClick"
    >
        <TableContainer>
            <TableHeader>
                <!-- Search Bar-->
                <SearchBar>
                    <Input
                        v-model="search"
                        id="search"
                        type="text"
                        placeholder="Order number search"
                        class="pl-10"
                    />
                </SearchBar>
                <!-- Filters -->
                <DivFlexCenter class="gap-5">
                    <Popover>
                        <PopoverTrigger> <Filter /> </PopoverTrigger>
                        <PopoverContent>
                            <div class="flex justify-end">
                                <Button
                                    @click="resetFilter"
                                    variant="link"
                                    class="text-end text-red-500 text-xs"
                                >
                                    Reset Filter
                                </Button>
                            </div>
                            <label class="text-xs">From</label>
                            <Input type="date" v-model="from" />
                            <label class="text-xs">To</label>
                            <Input type="date" v-model="to" />
                            <label class="text-xs">Store</label>
                            <Select v-model="branchId">
                                <SelectTrigger>
                                    <SelectValue placeholder="Select a store" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectGroup>
                                        <SelectLabel>Stores</SelectLabel>
                                        <SelectItem
                                            v-for="(value, key) in branches"
                                            :key="key"
                                            :value="key"
                                        >
                                            {{ value }}
                                        </SelectItem>
                                    </SelectGroup>
                                </SelectContent>
                            </Select>
                        </PopoverContent>
                    </Popover>
                </DivFlexCenter>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Vendor</TH>
                    <TH>Store</TH>
                    <TH>Order Placed At</TH>
                    <TH>Order #</TH>
                    <TH>Order Date</TH>
                    <TH>Order Items</TH>
                    <TH>Order Quantity</TH>
                    <TH>Receiving Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody>
                    <tr v-for="order in orders.data" :key="order.id">
                        <TD>{{ order.Id }}</TD>
                        <TD>{{ order.vendor?.Name ?? "N/A" }}</TD>
                        <TD>{{ order.branch?.Name ?? "N/A" }}</TD>
                        <TD>{{ order.created_at }}</TD>
                        <TD>{{ order.SONumber }}</TD>
                        <TD>{{ order.OrderDate }}</TD>
                        <TD>{{ order.Total_Item }}</TD>
                        <TD>{{ order.TOTALQUANTITY ?? 0 }}</TD>
                        <TD>
                            <Badge
                                :class="statusBadgeColor(order.Status)"
                                class="font-bold"
                                >{{ order.Status }}</Badge
                            >
                        </TD>
                        <TD>
                            <Button
                                @click="showOrderDetails(order.SONumber)"
                                variant="link"
                            >
                                <Eye />
                            </Button>
                        </TD>
                    </tr>
                </TableBody>
            </Table>
            <Pagination :data="orders" />
        </TableContainer>
    </Layout>
</template>

<!-- <Dialog v-model:open="visible">
    <DialogContent class="sm:max-w-[600px]">
        <DialogHeader>
            <DialogTitle>Upload SO Header</DialogTitle>
            <DialogDescription>
                Input all the important fields.
            </DialogDescription>
        </DialogHeader>
        <div class="space-y-5">
            <div class="space-y-1">
                <Label>Store</Label>
                <Select v-model="form.store">
                    <SelectTrigger>
                        <SelectValue placeholder="Select Store" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectLabel>Select Store</SelectLabel>
                            <SelectItem
                                v-for="(value, key) in branches"
                                :key="key"
                                :value="key"
                            >
                                {{ value }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>
                <FormError>{{ form.errors.store }}</FormError>
            </div>
            <div class="flex flex-col space-y-1">
                <Label>SO Date</Label>
                <Input type="date" v-model="form.store_order_date" />
                <FormError>{{
                    form.errors.store_order_date
                }}</FormError>
            </div>
            <div class="flex flex-col space-y-1">
                <Label>Orders</Label>
                <Input
                    type="file"
                    @input="form.orders_list = $event.target.files[0]"
                />
                <FormError>{{ form.errors.orders_list }}</FormError>
            </div>
            <div class="flex flex-col space-y-1">
                <Label>Orders Templates</Label>
                <ul>
                    <li>
                        GSI BAKERY:
                        <a
                            class="text-blue-500 underline"
                            href="/excel/gsi-bakery-template"
                            >Click to download</a
                        >
                    </li>
                    <li>
                        GSI PR:
                        <a
                            class="text-blue-500 underline"
                            href="/excel/gsi-pr-template"
                            >Click to download</a
                        >
                    </li>
                    <li>
                        PUL:
                        <a
                            class="text-blue-500 underline"
                            href="/excel/pul-template"
                            >Click to download</a
                        >
                    </li>
                </ul>
            </div>
        </div>
        <DialogFooter>
            <Button @click="proceed" type="submit"> Proceed </Button>
        </DialogFooter>
    </DialogContent>
</Dialog> -->
