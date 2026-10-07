<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PatientForm from '@/components/patients/PatientForm.vue';
import { create, index, store } from '@/routes/patients';
import type { SelectOption } from '@/types';

defineProps<{
    sexes: SelectOption[];
    canEditMedical: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Patients', href: index() },
            { title: 'New patient', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New patient" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">New patient</h1>
            <p v-if="!canEditMedical" class="text-sm text-muted-foreground">
                Medical information can only be added by a doctor or
                practitioner.
            </p>
        </div>

        <PatientForm
            :sexes="sexes"
            :can-edit-medical="canEditMedical"
            :action="store()"
            submit-label="Create patient"
        />
    </div>
</template>
