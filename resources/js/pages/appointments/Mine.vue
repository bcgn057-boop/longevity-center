<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import CancelAppointmentDialog from '@/components/appointments/CancelAppointmentDialog.vue';
import StatusBadge from '@/components/appointments/StatusBadge.vue';
import { cancel, index } from '@/routes/my-appointments';
import type { Appointment } from '@/types';

defineProps<{
    upcoming: Appointment[];
    past: Appointment[];
    linked: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My appointments', href: index() }],
    },
});
</script>

<template>
    <Head title="My appointments" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-8 p-4">
        <h1 class="text-xl font-semibold">My appointments</h1>

        <p v-if="!linked" class="text-sm text-muted-foreground">
            Your account is not linked to a patient record yet. Please contact
            the clinic.
        </p>

        <template v-else>
            <section class="space-y-3">
                <h2 class="text-base font-medium">Upcoming</h2>
                <p
                    v-if="upcoming.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    You have no upcoming appointments. To book one, please
                    contact the clinic.
                </p>
                <ul class="space-y-3">
                    <li
                        v-for="appointment in upcoming"
                        :key="appointment.id"
                        class="flex flex-wrap items-start justify-between gap-3 rounded-lg border p-4 text-sm"
                    >
                        <div class="space-y-1">
                            <div class="font-medium">
                                {{ appointment.date_label }},
                                {{ appointment.start }}–{{ appointment.end }}
                            </div>
                            <div>{{ appointment.type_label }}</div>
                            <div class="text-muted-foreground">
                                With {{ appointment.doctor.name }}
                            </div>
                            <div v-if="appointment.reason">
                                {{ appointment.reason }}
                            </div>
                            <StatusBadge
                                :status="appointment.status"
                                :label="appointment.status_label"
                            />
                        </div>
                        <CancelAppointmentDialog
                            v-if="
                                appointment.status === 'scheduled' &&
                                !appointment.has_started
                            "
                            :url="cancel(appointment.id).url"
                            :can-give-reason="true"
                            description="The clinic will see that you cancelled. To book a new time, please contact the clinic."
                        />
                    </li>
                </ul>
            </section>

            <section v-if="past.length > 0" class="space-y-3">
                <h2 class="text-base font-medium">Past</h2>
                <ul class="divide-y rounded-lg border text-sm">
                    <li
                        v-for="appointment in past"
                        :key="appointment.id"
                        class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                        <span>
                            {{ appointment.date_label }},
                            {{ appointment.start }},
                            {{ appointment.type_label }} with
                            {{ appointment.doctor.name }}
                        </span>
                        <StatusBadge
                            :status="appointment.status"
                            :label="appointment.status_label"
                        />
                    </li>
                </ul>
            </section>
        </template>
    </div>
</template>
