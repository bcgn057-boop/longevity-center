<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PatientDetails from '@/components/patients/PatientDetails.vue';
import { myProfile } from '@/routes';
import type { Patient } from '@/types';

defineProps<{
    patient: Patient | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My profile', href: myProfile() }],
    },
});
</script>

<template>
    <Head title="My profile" />

    <div class="flex h-full max-w-4xl flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">My profile</h1>

        <PatientDetails v-if="patient" :patient="patient" />

        <p v-else class="text-sm text-muted-foreground">
            Your account is not linked to a patient record yet. Please contact
            the clinic.
        </p>

        <p v-if="patient" class="text-xs text-muted-foreground">
            To correct any of these details, please contact the clinic.
        </p>
    </div>
</template>
