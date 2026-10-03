<script setup>
import PrimeDialog from "primevue/dialog";
import { AlertTriangle } from "lucide-vue-next";

const visible = defineModel("visible", { type: Boolean, default: false });

defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    // false when this approval only moves the wastage on to Level 2, which is where stock is deducted.
    deductsStock: {
        type: Boolean,
        default: true,
    },
    processing: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["confirm"]);

const formatQty = (value) => Number(value ?? 0).toLocaleString("en-PH", { maximumFractionDigits: 4 });
</script>

<template>
    <PrimeDialog
        v-model:visible="visible"
        modal
        header="Approve with Negative Stock?"
        :closable="!processing"
        :style="{ width: '46rem' }"
        :breakpoints="{ '960px': '80vw', '641px': '94vw' }"
    >
        <div class="space-y-4 text-sm">
            <div class="flex gap-3 rounded-md border-2 border-red-400 bg-red-50 px-4 py-3 text-red-900">
                <AlertTriangle class="mt-0.5 h-5 w-5 flex-shrink-0 text-red-600" />
                <div class="space-y-1">
                    <p class="font-bold uppercase">
                        {{ items.length }} {{ items.length === 1 ? "item does" : "items do" }} not have enough stock on hand
                    </p>
                    <p v-if="deductsStock">
                        Approving this wastage will make the stock on hand (SOH) of
                        {{ items.length === 1 ? "this item" : "these items" }} go <strong>negative</strong>.
                    </p>
                    <p v-else>
                        This approval sends the wastage to Level 2. When Level 2 approves it, the stock on hand (SOH) of
                        {{ items.length === 1 ? "this item" : "these items" }} will go <strong>negative</strong> unless more stock
                        arrives first.
                    </p>
                </div>
            </div>

            <div class="max-h-[45vh] overflow-auto rounded-md border border-gray-200">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="sticky top-0 bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wider text-gray-600">Item</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-gray-600">Current SOH</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-gray-600">Wastage</th>
                            <th class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wider text-red-700">SOH After</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-for="item in items" :key="item.item_code + item.uom">
                            <td class="px-3 py-2">
                                <span class="block font-medium text-gray-900">{{ item.item_description }}</span>
                                <span class="text-xs text-gray-500">{{ item.item_code }}<template v-if="item.uom"> · {{ item.uom }}</template></span>
                            </td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ formatQty(item.available) }}</td>
                            <td class="px-3 py-2 text-right text-gray-700">{{ formatQty(item.required) }}</td>
                            <td class="px-3 py-2 text-right font-bold text-red-600">{{ formatQty(item.resulting) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <p class="font-medium text-gray-900">Do you still want to approve this wastage?</p>
        </div>

        <template #footer>
            <Button variant="outline" :disabled="processing" @click="visible = false">Cancel</Button>
            <Button class="bg-red-600 hover:bg-red-700" :disabled="processing" @click="emit('confirm')">
                {{ processing ? "Approving..." : "Yes, approve with negative stock" }}
            </Button>
        </template>
    </PrimeDialog>
</template>
