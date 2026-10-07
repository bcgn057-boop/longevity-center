<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PatientForm from '@/components/patients/PatientForm.vue';
import { index, update } from '@/routes/patients';
import type { Patient, SelectOption } from '@/types';

const props = defineProps<{
    patient: Patient;
    sexes: SelectOption[];
    canEditMedical: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: index() }],
    },
});
</script>

<template>
    <Head :title="`Edit ${props.patient.full_name}`" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">
                Edit {{ props.patient.full_name }}
            </h1>
            <p v-if="!canEditMedical" class="text-sm text-muted-foreground">
                Medical information can only be edited by a doctor or
                practitioner.
            </p>
        </div>

        <PatientForm
            :patient="props.patient"
            :sexes="sexes"
            :can-edit-medical="canEditMedical"
            :action="update(props.patient.id)"
            submit-label="Save changes"
        />
    </div>
</template>
