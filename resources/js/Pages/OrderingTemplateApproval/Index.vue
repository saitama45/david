<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
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
import { useToast } from 'primevue/usetoast';

const toast = useToast();

const props = defineProps({
  suppliers: Array,
  // The most approval levels a template can have.
  maxLevels: {
    type: Number,
    default: 1,
  },
  // Per level: its approve / reject permissions and the roles holding them.
  levelRoles: {
    type: Array,
    default: () => [],
  },
});

const suppliers = ref(props.suppliers.map((supplier) => ({ ...supplier })));

const levelOptions = computed(() =>
  Array.from({ length: props.maxLevels }, (_, index) => ({
    label: index === 0 ? '1 level' : `${index + 1} levels`,
    value: index + 1,
  }))
);

// The highest level any template that waits for approval goes to.
const levelsInUse = computed(() =>
  Math.max(1, ...suppliers.value.filter((supplier) => supplier.is_forapproval_massorders).map((supplier) => supplier.mass_order_approval_levels))
);

// admin holds every permission, so it does not count as a level's approver.
const approverRoles = (level) =>
  (props.levelRoles.find((row) => row.level === level)?.approve_roles ?? []).filter((name) => name !== 'admin');

// Levels of this template that no role (other than admin) can approve yet.
const levelsWithoutApprover = (supplier) => {
  if (!supplier.is_forapproval_massorders) return [];
  return Array.from({ length: supplier.mass_order_approval_levels }, (_, index) => index + 1)
    .filter((level) => approverRoles(level).length === 0);
};

const updateSupplier = (supplier, field, value) => {
  const previous = supplier[field];
  supplier[field] = value;

  router.post(route('ordering-template-approval.update', { supplier: supplier.id }), {
    is_forapproval_massorders: supplier.is_forapproval_massorders,
    mass_order_approval_levels: supplier.mass_order_approval_levels,
  }, {
    preserveState: true,
    preserveScroll: true,
    onSuccess: () => {
      toast.add({ severity: 'success', summary: 'Success', detail: 'Supplier update saved.', life: 3000 });
    },
    onError: (errors) => {
      supplier[field] = previous;
      toast.add({
        severity: 'error',
        summary: 'Error',
        detail: errors.error ?? errors.mass_order_approval_levels ?? 'Failed to save supplier update.',
        life: 5000,
      });
    },
  });
};
</script>

<template>
  <Head title="Ordering Template Approval" />
  <Layout>
    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle>Ordering Template Approval</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p class="text-sm text-gray-600">
                        Turn on approval for a template to send its mass orders to Mass Orders Approval, then choose how many
                        levels approve them. An order waits for Level 1, then Level 2, and so on, and is approved only when its
                        last level approves. Any level can reject it. A new number of levels applies to orders nobody has approved
                        yet; an order already approved at a level keeps the levels it started with.
                    </p>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Supplier Code</TableHead>
                                <TableHead>Name</TableHead>
                                <TableHead>Is For Approval (Mass Orders)</TableHead>
                                <TableHead>Approval Levels</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="supplier in suppliers" :key="supplier.id">
                                <TableCell>{{ supplier.supplier_code }}</TableCell>
                                <TableCell>{{ supplier.name }}</TableCell>
                                <TableCell>
                                    <ToggleSwitch
                                        :model-value="supplier.is_forapproval_massorders"
                                        :disabled="supplier.always_for_approval && supplier.is_forapproval_massorders"
                                        @update:model-value="(value) => updateSupplier(supplier, 'is_forapproval_massorders', value)"
                                    />
                                </TableCell>
                                <TableCell>
                                    <Select
                                        :model-value="supplier.mass_order_approval_levels"
                                        :options="levelOptions"
                                        option-label="label"
                                        option-value="value"
                                        :disabled="!supplier.is_forapproval_massorders"
                                        class="w-32"
                                        @update:model-value="(value) => updateSupplier(supplier, 'mass_order_approval_levels', value)"
                                    />
                                    <p
                                        v-if="levelsWithoutApprover(supplier).length"
                                        class="mt-1 text-xs text-amber-700"
                                    >
                                        No role can approve Level {{ levelsWithoutApprover(supplier).join(', Level ') }} yet.
                                    </p>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>Approvers per Level</CardTitle>
                </CardHeader>
                <CardContent class="space-y-4">
                    <p class="text-sm text-gray-600">
                        Each level has its own permissions. Give them to the approvers' roles in Roles, under Ordering &gt;
                        Regular Mass Orders. Approvers also need "view mass order approval" and to be assigned to the order's store.
                    </p>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Level</TableHead>
                                <TableHead>Permission to Approve</TableHead>
                                <TableHead>Permission to Reject</TableHead>
                                <TableHead>Roles that can Approve</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="row in levelRoles" :key="row.level">
                                <TableCell class="font-semibold">Level {{ row.level }}</TableCell>
                                <TableCell><code class="text-xs">{{ row.approve_permission }}</code></TableCell>
                                <TableCell><code class="text-xs">{{ row.reject_permission }}</code></TableCell>
                                <TableCell>
                                    <span v-if="approverRoles(row.level).length">{{ approverRoles(row.level).join(', ') }}</span>
                                    <span
                                        v-else-if="row.level <= levelsInUse"
                                        class="text-sm font-medium text-amber-700"
                                    >No role yet: orders will wait at this level.</span>
                                    <span v-else class="text-sm text-gray-400">None (no template uses this level)</span>
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
