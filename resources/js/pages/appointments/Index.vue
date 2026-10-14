<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';
import StatusBadge from '@/components/appointments/StatusBadge.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index, show } from '@/routes/appointments';
import type {
    Appointment,
    DoctorOption,
    Paginated,
    SelectOption,
} from '@/types';

type Filters = {
    from: string;
    to: string;
    doctor_id: string;
    status: string;
};

const props = defineProps<{
    appointments: Paginated<Appointment>;
    filters: Filters;
    doctors: DoctorOption[];
    statuses: SelectOption[];
    timezone: string;
    can: { create: boolean };
    errors?: Record<string, string>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Appointments', href: index() }],
    },
});

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30';

const form = reactive<Filters>({ ...props.filters });

function apply(): void {
    const query = Object.fromEntries(
        Object.entries(form).filter(([, value]) => value !== ''),
    );

    router.get(index().url, query, {
        preserveState: true,
        preserveScroll: true,
    });
}

function addDays(date: string, days: number): string {
    const value = new Date(`${date}T00:00:00Z`);
    value.setUTCDate(value.getUTCDate() + days);

    return value.toISOString().slice(0, 10);
}

// Move the whole range by its own length (e.g. week by week).
function shift(direction: 1 | -1): void {
    const from = new Date(`${form.from}T00:00:00Z`).getTime();
    const to = new Date(`${form.to}T00:00:00Z`).getTime();
    const length = Math.round((to - from) / 86_400_000) + 1;

    form.from = addDays(form.from, direction * length);
    form.to = addDays(form.to, direction * length);
    apply();
}
</script>

<template>
    <Head title="Appointments" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Appointments</h1>
                <p class="text-sm text-muted-foreground">
                    {{ appointments.total }} appointments. Times are in
                    {{ timezone }}.
                </p>
            </div>
            <Button v-if="can.create" as-child>
                <Link :href="create()">Book appointment</Link>
            </Button>
        </div>

        <form
            :action="index().url"
            method="get"
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="apply"
        >
            <div class="grid gap-1.5">
                <Label for="from">From</Label>
                <Input id="from" v-model="form.from" name="from" type="date" />
            </div>
            <div class="grid gap-1.5">
                <Label for="to">To</Label>
                <Input id="to" v-model="form.to" name="to" type="date" />
            </div>
            <div class="grid gap-1.5">
                <Label for="doctor_id">Clinician</Label>
                <select
                    id="doctor_id"
                    v-model="form.doctor_id"
                    name="doctor_id"
                    :class="selectClass"
                >
                    <option value="">All clinicians</option>
                    <option
                        v-for="doctor in doctors"
                        :key="doctor.id"
                        :value="doctor.id.toString()"
                    >
                        {{ doctor.name }}
                    </option>
                </select>
            </div>
            <div class="grid gap-1.5">
                <Label for="status">Status</Label>
                <select
                    id="status"
                    v-model="form.status"
                    name="status"
                    :class="selectClass"
                >
                    <option value="">Any status</option>
                    <option
                        v-for="status in statuses"
                        :key="status.value"
                        :value="status.value"
                    >
                        {{ status.label }}
                    </option>
                </select>
            </div>
            <Button type="submit">Show</Button>
            <div class="flex gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="shift(-1)"
                >
                    Previous
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="shift(1)"
                >
                    Next
                </Button>
            </div>
        </form>
        <InputError :message="errors?.to ?? errors?.from" />

        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Time</th>
                        <th class="px-3 py-2 font-medium">Type</th>
                        <th class="px-3 py-2 font-medium">Patient</th>
                        <th class="px-3 py-2 font-medium">Clinician</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="appointments.data.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            No appointments in this range.
                        </td>
                    </tr>
                    <tr
                        v-for="appointment in appointments.data"
                        :key="appointment.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-3 py-2">{{ appointment.date_label }}</td>
                        <td class="px-3 py-2">
                            <Link
                                :href="show(appointment.id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ appointment.start }}–{{ appointment.end }}
                            </Link>
                        </td>
                        <td class="px-3 py-2">{{ appointment.type_label }}</td>
                        <td class="px-3 py-2">
                            {{ appointment.patient.full_name }}
                            <span
                                class="ml-1 font-mono text-xs text-muted-foreground"
                            >
                                {{ appointment.patient.medical_record_number }}
                            </span>
                        </td>
                        <td class="px-3 py-2">{{ appointment.doctor.name }}</td>
                        <td class="px-3 py-2">
                            <StatusBadge
                                :status="appointment.status"
                                :label="appointment.status_label"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="appointments.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <span class="text-muted-foreground">
                Page {{ appointments.current_page }} of
                {{ appointments.last_page }}
            </span>
            <div class="flex gap-2">
                <Button
                    v-if="appointments.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="appointments.prev_page_url" preserve-scroll>
                        Previous
                    </Link>
                </Button>
                <Button
                    v-if="appointments.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="appointments.next_page_url" preserve-scroll>
                        Next
                    </Link>
                </Button>
            </div>
        </div>
    </div>
</template>
