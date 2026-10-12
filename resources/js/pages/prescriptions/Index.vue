<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { ref } from 'vue';
import NewPrescriptionForm from '@/components/prescriptions/NewPrescriptionForm.vue';
import PrescriptionCard from '@/components/prescriptions/PrescriptionCard.vue';
import { Button } from '@/components/ui/button';
import { index as patientsIndex, show as showPatient } from '@/routes/patients';
import type { PrescriptionOptions, StaffPrescription } from '@/types';

const props = defineProps<{
    patient: {
        id: number;
        full_name: string;
        medical_record_number: string | null;
    };
    prescriptions: StaffPrescription[];
    options: PrescriptionOptions;
    today: string;
    can: { manage: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: patientsIndex() }],
    },
});

// Open the builder straight away when there is nothing to show yet.
const creating = ref(props.can.manage && props.prescriptions.length === 0);
</script>

<template>
    <Head :title="`Prescriptions: ${patient.full_name}`" />

    <div class="flex h-full max-w-6xl flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Prescriptions</h1>
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
                <p
                    v-if="!can.manage"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    Only doctors can issue or change prescriptions.
                </p>
            </div>
            <Button v-if="can.manage && !creating" @click="creating = true">
                <Plus class="size-4" /> New prescription
            </Button>
        </div>

        <NewPrescriptionForm
            v-if="can.manage && creating"
            :patient-id="patient.id"
            :options="options"
            :today="today"
            @done="creating = false"
        />

        <p
            v-if="prescriptions.length === 0 && !creating"
            class="text-sm text-muted-foreground"
        >
            No prescriptions yet.
        </p>

        <PrescriptionCard
            v-for="prescription in prescriptions"
            :key="prescription.id"
            :prescription="prescription"
            :options="options"
            :can-manage="can.manage"
        />

        <p class="text-xs text-muted-foreground">
            Adherence is what the patient ticked off in the portal. Days are
            counted in the clinic's timezone.
        </p>
    </div>
</template>
