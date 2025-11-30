<script setup>
import { ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import ToggleSwitch from 'primevue/toggleswitch';
import { useToast } from 'primevue/usetoast'; // Import useToast

const toast = useToast(); // Instantiate the toast service

const props = defineProps({
  suppliers: Array,
});

const suppliers = ref(props.suppliers);

const updateSupplierApproval = (supplier) => {
  const form = useForm({
    is_forapproval_massorders: supplier.is_forapproval_massorders,
  });

  form.post(route('ordering-template-approval.update', { supplier: supplier.id }), {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => { // Add onSuccess callback
      toast.add({
        severity: 'success',
        summary: 'Success',
        detail: 'Supplier update saved.',
        life: 3000,
      });
    },
    onError: (errors) => {
      // Revert the toggle state on error
      supplier.is_forapproval_massorders = !supplier.is_forapproval_massorders;
      console.error('Error updating supplier approval:', errors);
      toast.add({ // Add error toast
        severity: 'error',
        summary: 'Error',
        detail: 'Failed to save supplier update.',
        life: 3000,
      });
    }
  });
};
</script>

<template>
  <Head title="Ordering Template Approval" />
  <Layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <Card>
                <CardHeader>
                    <CardTitle>Ordering Template Approval</CardTitle>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Supplier Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Is For Approval (Mass Orders)</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="supplier in suppliers" :key="supplier.id">
                                <TableCell>{{ supplier.supplier_code }}</TableCell>
                                <TableCell>{{ supplier.name }}</TableCell>
                                <TableCell>
                                    <ToggleSwitch
                                        v-model="supplier.is_forapproval_massorders"
                                        @change="updateSupplierApproval(supplier)"
                                    />
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    </div>
  </Layout>
</template>