<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cancel, index } from '@/routes/lab-requisitions';
import { create as enterReport, index as labsIndex } from '@/routes/labs';
import type { LabRequisitionRow } from '@/types';

defineProps<{
    requisitions: LabRequisitionRow[];
    filters: { status: string };
    statuses: { value: string; label: string }[];
    // Set for partner lab users: the lab whose requisitions these are.
    labPartner: string | null;
    can: { enterResults: boolean; cancel: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Lab results', href: labsIndex() },
            { title: 'Requisitions', href: index() },
        ],
    },
});

const cancelForm = useForm({});

function filter(status: string): void {
    router.get(index().url, { status }, { preserveScroll: true });
}

function cancelRequisition(requisition: LabRequisitionRow): void {
    cancelForm.post(cancel(requisition.id).url, { preserveScroll: true });
}

const variant = (status: string) =>
    status === 'ordered'
        ? 'default'
        : status === 'reported'
          ? 'secondary'
          : 'outline';
</script>

<template>
    <Head title="Lab requisitions" />

    <div class="flex h-full max-w-6xl flex-1 flex-col gap-4 p-4">
        <div>
            <h1 class="text-xl font-semibold">Lab requisitions</h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="labPartner">
                    Tests ordered from {{ labPartner }}. You only see your own
                    lab's requisitions.
                </template>
                <template v-else>
                    Tests ordered from partner labs. Order new tests from a
                    patient's record.
                </template>
            </p>
        </div>

        <div class="flex flex-wrap gap-2">
            <Button
                v-for="status in [...statuses, { value: 'all', label: 'All' }]"
                :key="status.value"
                size="sm"
                :variant="
                    filters.status === status.value ? 'default' : 'outline'
                "
                @click="filter(status.value)"
            >
                {{ status.label }}
            </Button>
        </div>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Ordered</th>
                        <th class="px-3 py-2 font-medium">Patient</th>
                        <th class="px-3 py-2 font-medium">Lab</th>
                        <th class="px-3 py-2 font-medium">Tests</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="requisitions.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            No requisitions.
                        </td>
                    </tr>
                    <tr
                        v-for="requisition in requisitions"
                        :key="requisition.id"
                        class="border-b align-top last:border-0"
                    >
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ requisition.ordered_at }}
                            <div class="text-xs text-muted-foreground">
                                {{ requisition.ordered_by ?? '—' }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            {{ requisition.patient.full_name }}
                            <div
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ requisition.patient.medical_record_number }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            {{ requisition.lab_partner.name }}
                        </td>
                        <td class="px-3 py-2">
                            {{ requisition.tests.join(', ') }}
                            <div
                                v-if="requisition.instructions"
                                class="mt-1 text-xs text-muted-foreground"
                            >
                                {{ requisition.instructions }}
                            </div>
                        </td>
                        <td class="px-3 py-2">
                            <Badge :variant="variant(requisition.status)">
                                {{ requisition.status_label }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <Button
                                v-if="
                                    can.enterResults &&
                                    requisition.status !== 'cancelled'
                                "
                                size="sm"
                                variant="outline"
                                as-child
                            >
                                <Link
                                    :href="
                                        enterReport({
                                            query: {
                                                requisition: requisition.id,
                                            },
                                        })
                                    "
                                >
                                    Enter results
                                </Link>
                            </Button>
                            <Button
                                v-if="
                                    can.cancel &&
                                    requisition.status === 'ordered'
                                "
                                size="sm"
                                variant="ghost"
                                :disabled="cancelForm.processing"
                                @click="cancelRequisition(requisition)"
                            >
                                Cancel
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
