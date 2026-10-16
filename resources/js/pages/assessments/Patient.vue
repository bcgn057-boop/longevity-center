<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AssessmentFormCard from '@/components/assessments/AssessmentFormCard.vue';
import AssessmentSummary from '@/components/assessments/AssessmentSummary.vue';
import { index as patientsIndex, show as showPatient } from '@/routes/patients';
import { store } from '@/routes/patients/assessments';
import type {
    AssessmentEntry,
    AssessmentForm,
    LatestMeasurement,
} from '@/types';

defineProps<{
    patient: {
        id: number;
        full_name: string;
        medical_record_number: string | null;
    };
    forms: AssessmentForm[];
    latest: LatestMeasurement[];
    history: AssessmentEntry[];
    today: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: patientsIndex() }],
    },
});
</script>

<template>
    <Head :title="`Measurements: ${patient.full_name}`" />

    <div class="flex h-full max-w-5xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">
                Measurements and functional tests
            </h1>
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
        </div>

        <AssessmentSummary :latest="latest" :history="history" />

        <div class="grid gap-6 lg:grid-cols-2">
            <AssessmentFormCard
                v-for="form in forms"
                :key="form.kind"
                :form="form"
                :url="store(patient.id).url"
                :today="today"
                :hint="
                    form.kind === 'at_center'
                        ? 'Only doctors and practitioners can record these, here in the clinic.'
                        : 'The patient can also enter these from their portal.'
                "
            />
        </div>
    </div>
</template>
