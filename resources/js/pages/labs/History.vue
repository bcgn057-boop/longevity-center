<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LabHistory from '@/components/labs/LabHistory.vue';
import { show as showReport } from '@/routes/labs';
import { index as patientsIndex, show as showPatient } from '@/routes/patients';
import type { LabHistory as History } from '@/types';

defineProps<{
    patient: {
        id: number;
        full_name: string;
        medical_record_number: string | null;
    };
    history: History;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: patientsIndex() }],
    },
});
</script>

<template>
    <Head :title="`Lab history: ${patient.full_name}`" />

    <div class="flex h-full max-w-7xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Lab history</h1>
            <p class="text-sm">
                <Link
                    :href="showPatient(patient.id)"
                    class="font-medium underline-offset-4 hover:underline"
                >
                    {{ patient.full_name }}
                </Link>
                <span class="ml-2 font-mono text-xs text-muted-foreground">
                    {{ patient.medical_record_number }}
                </span>
            </p>
            <p class="text-sm text-muted-foreground">
                Every report from every lab, including those still under review.
            </p>
        </div>

        <p
            v-if="history.timeline.length === 0"
            class="text-sm text-muted-foreground"
        >
            No lab reports yet.
        </p>
        <LabHistory
            v-else
            :history="history"
            :report-href="(id) => showReport(id)"
            show-status
        />
    </div>
</template>
