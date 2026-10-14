<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppointmentForm from '@/components/appointments/AppointmentForm.vue';
import { index, update } from '@/routes/appointments';
import type { Appointment, AppointmentTypeOption, DoctorOption } from '@/types';

const props = defineProps<{
    appointment: Appointment;
    doctors: DoctorOption[];
    types: AppointmentTypeOption[];
    canEditReason: boolean;
    timezone: string;
    today: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Appointments', href: index() }],
    },
});
</script>

<template>
    <Head title="Reschedule appointment" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Reschedule appointment</h1>

        <AppointmentForm
            :appointment="props.appointment"
            :patient="null"
            :doctors="doctors"
            :types="types"
            :can-edit-reason="canEditReason"
            :timezone="timezone"
            :today="today"
            :action="update(props.appointment.id)"
            submit-label="Save changes"
        />
    </div>
</template>
