<script setup>
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from "@/components/ui/select";
import { router } from "@inertiajs/vue3";
const filter = ref("all");
const changeFilter = (currentFilter) => {};
const variant = ref("");
const isLoading = false;

const isVariantChoicesVisible = ref(false);
const showVariantChoices = () => {
    isVariantChoicesVisible.value = true;
};

const proceed = () => {
    console.log(variant.value);
    router.get(`/dts-orders/create/${variant.value}`);
};

const variants = [
    {
        value: "ice cream",
        label: "Ice Cream",
    },
    {
        value: "salmon",
        label: "Salmon",
    },
    {
        value: "fruits and vegetables",
        label: "Fruits and Vegetables",
    },
];

const handleClick = () => {
    router.get("/dts-orders/create");
};
</script>
<template>
    <Layout
        heading="DTS Orders"
        :hasButton="true"
        :handleClick="showVariantChoices"
        buttonName="Create New Order"
    >
        <FilterTab>
            <FilterTabButton
                label="All"
                filter="all"
                :currentFilter="filter"
                @click="changeFilter('all')"
            />
            <FilterTabButton
                label="Approved"
                filter="approved"
                :currentFilter="filter"
                @click="changeFilter('approved')"
            />
            <FilterTabButton
                label="Pending"
                filter="pending"
                :currentFilter="filter"
                @click="changeFilter('pending')"
            />
            <FilterTabButton
                label="Rejected"
                filter="rejected"
                :currentFilter="filter"
                @click="changeFilter('rejected')"
            />
        </FilterTab>

        <TableContainer>
            <TableHeader>
                <SearchBar>
                    <Input id="search" placeholder="Search..." class="pl-10" />
                </SearchBar>
            </TableHeader>

            <Table>
                <TableHead>
                    <TH>Id</TH>
                    <TH>Supplier</TH>
                    <TH>Store</TH>
                    <TH>Order #</TH>
                    <TH>Order Date</TH>
                    <TH>Order Placed Date</TH>
                    <TH>Order Approval Status</TH>
                    <TH>Actions</TH>
                </TableHead>
                <TableBody> </TableBody>
            </Table>
        </TableContainer>

        <Dialog v-model:open="isVariantChoicesVisible">
            <DialogContent class="sm:max-w-[600px]">
                <DialogHeader>
                    <DialogTitle>Order Variant</DialogTitle>
                    <DialogDescription>
                        Please select an order variant to proceed.
                    </DialogDescription>
                </DialogHeader>
                <Select v-model="variant">
                    <SelectTrigger>
                        <SelectValue placeholder="Select a variant" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            <SelectLabel>Variants</SelectLabel>
                            <SelectItem
                                v-for="variant in variants"
                                :value="variant.value"
                            >
                                {{ variant.label }}
                            </SelectItem>
                        </SelectGroup>
                    </SelectContent>
                </Select>

                <DialogFooter>
                    <Button @click="proceed" type="submit" class="gap-2">
                        Proceed
                        <span><Loading v-if="isLoading" /></span>
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </Layout>
</template>
