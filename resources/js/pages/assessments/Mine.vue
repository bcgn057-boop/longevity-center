<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AssessmentFormCard from '@/components/assessments/AssessmentFormCard.vue';
import AssessmentSummary from '@/components/assessments/AssessmentSummary.vue';
import { store } from '@/routes/my-measurements';
import type {
    AssessmentEntry,
    AssessmentForm,
    LatestMeasurement,
} from '@/types';

defineProps<{
    linked: boolean;
    // Only the at-home form: functional tests are done at the clinic.
    forms: AssessmentForm[];
    latest: LatestMeasurement[];
    history: AssessmentEntry[];
    today: string;
}>();
</script>

<template>
    <Head title="My measurements" />

    <div class="flex h-full max-w-5xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">My measurements</h1>
            <p class="text-sm text-muted-foreground">
                Record your height, weight, waist and blood pressure at home.
                Grip strength, chair stand and walk tests are done with your
                clinician at the clinic and appear here afterwards.
            </p>
        </div>

        <p
            v-if="!linked"
            class="rounded-lg border px-4 py-3 text-sm text-muted-foreground"
        >
            Your account isn't linked to a patient record yet. Please contact
            the clinic.
        </p>

        <template v-else>
            <AssessmentFormCard
                v-for="form in forms"
                :key="form.kind"
                :form="form"
                :url="store().url"
                :today="today"
                hint="Measure in the morning if you can, before eating."
            />
            <AssessmentSummary :latest="latest" :history="history" />
        </template>
    </div>
</template>
