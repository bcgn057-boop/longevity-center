<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import CancelAppointmentDialog from '@/components/appointments/CancelAppointmentDialog.vue';
import StatusBadge from '@/components/appointments/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { cancel, complete, edit, index } from '@/routes/appointments';
import { show as showPatient } from '@/routes/patients';
import type { Appointment } from '@/types';

const props = defineProps<{
    appointment: Appointment;
    can: {
        update: boolean;
        complete: boolean;
        cancel: boolean;
        viewReason: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Appointments', href: index() }],
    },
});

const completeForm = useForm({});

function submitComplete(): void {
    completeForm.post(complete(props.appointment.id).url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Appointment" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">
                    {{ appointment.date_label }}, {{ appointment.start }}–{{
                        appointment.end
                    }}
                </h1>
                <div class="mt-1 flex items-center gap-2">
                    <StatusBadge
                        :status="appointment.status"
                        :label="appointment.status_label"
                    />
                    <span class="text-sm text-muted-foreground">
                        {{ appointment.duration_minutes }} min
                    </span>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <Button v-if="can.update" variant="outline" size="sm" as-child>
                    <Link :href="edit(appointment.id)">Reschedule</Link>
                </Button>
                <Button
                    v-if="can.complete"
                    size="sm"
                    :disabled="completeForm.processing"
                    @click="submitComplete"
                >
                    Mark completed
                </Button>
                <CancelAppointmentDialog
                    v-if="can.cancel"
                    :url="cancel(appointment.id).url"
                    :can-give-reason="can.viewReason"
                    description="The slot is freed for other bookings. The appointment stays in the patient's history."
                />
            </div>
        </div>
        <InputError
            :message="(completeForm.errors as Record<string, string>).status"
        />

        <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-muted-foreground">Patient</dt>
                <dd>
                    <Link
                        :href="showPatient(appointment.patient.id)"
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ appointment.patient.full_name }}
                    </Link>
                    <span class="ml-2 font-mono text-xs text-muted-foreground">
                        {{ appointment.patient.medical_record_number }}
                    </span>
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Clinician</dt>
                <dd>{{ appointment.doctor.name }}</dd>
            </div>
            <div>
                <dt class="text-muted-foreground">Type</dt>
                <dd>{{ appointment.type_label }}</dd>
            </div>
            <div v-if="'reason' in appointment" class="sm:col-span-2">
                <dt class="text-muted-foreground">Reason for visit</dt>
                <dd class="whitespace-pre-line">
                    {{ appointment.reason || '—' }}
                </dd>
            </div>
            <template v-if="appointment.status === 'cancelled'">
                <div>
                    <dt class="text-muted-foreground">Cancelled</dt>
                    <dd>
                        {{ appointment.cancelled_at }}
                        <span v-if="appointment.cancelled_by">
                            by {{ appointment.cancelled_by }}
                        </span>
                    </dd>
                </div>
                <div v-if="'cancellation_reason' in appointment">
                    <dt class="text-muted-foreground">Cancellation reason</dt>
                    <dd class="whitespace-pre-line">
                        {{ appointment.cancellation_reason || '—' }}
                    </dd>
                </div>
            </template>
        </dl>

        <p v-if="!can.viewReason" class="text-sm text-muted-foreground">
            The reason for the visit is only visible to clinic staff and the
            patient.
        </p>
    </div>
</template>
