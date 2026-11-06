<script setup>
import { ref, watch } from "vue";
import axios from "axios";
import Dialog from "primevue/dialog";
import Button from "primevue/button";
import { useToast } from "@/composables/useToast";

// Adds a CPO Orders vendor, or edits the one given. There is no delete.
const props = defineProps({
    visible: { type: Boolean, default: false },
    // The vendor to edit; null adds a new one.
    vendor: { type: Object, default: null },
});

const emit = defineEmits(["update:visible", "saved"]);

const { toast } = useToast();
const form = ref({ name: "", tin: "", address: "" });
const errors = ref({});
const saving = ref(false);

watch(() => props.visible, (open) => {
    if (!open) return;
    errors.value = {};
    form.value = props.vendor
        ? { name: props.vendor.name, tin: props.vendor.tin ?? "", address: props.vendor.address ?? "" }
        : { name: "", tin: "", address: "" };
});

const close = () => emit("update:visible", false);

const save = async () => {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = props.vendor
            ? await axios.put(route("cpo-orders.vendors.update", props.vendor.id), form.value)
            : await axios.post(route("cpo-orders.vendors.store"), form.value);
        toast.add({ severity: "success", summary: "Vendor saved", detail: data.name, life: 3000 });
        emit("saved", data);
        close();
    } catch (error) {
        if (error.response?.status === 422) {
            errors.value = Object.fromEntries(Object.entries(error.response.data.errors || {}).map(([key, list]) => [key, list[0]]));
        } else {
            toast.add({ severity: "error", summary: "Error", detail: "The vendor could not be saved.", life: 5000 });
        }
    } finally {
        saving.value = false;
    }
};
</script>

<template>
    <Dialog :visible="visible" modal :header="vendor ? 'Edit Vendor' : 'Add New Vendor'" :style="{ width: '32rem', maxWidth: '95vw' }" @update:visible="emit('update:visible', $event)">
        <form class="space-y-4" @submit.prevent="save">
            <div>
                <label for="vendor_name" class="block text-sm font-medium text-gray-700 mb-1">Vendor Name <span class="text-red-500">*</span></label>
                <input id="vendor_name" v-model="form.name" type="text" maxlength="255" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
            </div>
            <div>
                <label for="vendor_tin" class="block text-sm font-medium text-gray-700 mb-1">TIN # <span class="text-xs text-gray-400">(optional)</span></label>
                <input id="vendor_tin" v-model="form.tin" type="text" maxlength="50" class="flex h-10 w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm" />
                <p v-if="errors.tin" class="mt-1 text-sm text-red-600">{{ errors.tin }}</p>
            </div>
            <div>
                <label for="vendor_address" class="block text-sm font-medium text-gray-700 mb-1">Address <span class="text-red-500">*</span></label>
                <textarea id="vendor_address" v-model="form.address" rows="3" maxlength="500" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm"></textarea>
                <p v-if="errors.address" class="mt-1 text-sm text-red-600">{{ errors.address }}</p>
            </div>
            <p v-if="vendor" class="text-xs text-gray-500">
                The change applies to CPO orders placed from now on. Orders already placed keep the details they were placed with.
            </p>
            <div class="flex justify-end gap-2">
                <Button type="button" severity="secondary" outlined @click="close">Cancel</Button>
                <Button type="submit" :disabled="saving">Save Vendor</Button>
            </div>
        </form>
    </Dialog>
</template>
