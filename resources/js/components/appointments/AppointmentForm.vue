<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Appointment, DoctorOption, PatientOption } from '@/types';

const props = defineProps<{
    appointment?: Appointment;
    // When booking, the patient chosen on the page; ignored when rescheduling.
    patient: PatientOption | null;
    doctors: DoctorOption[];
    canEditReason: boolean;
    timezone: string;
    today: string;
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
}>();

const DURATIONS = [15, 30, 45, 60, 90];
const DEFAULT_DURATION = 30;
const MINUTES_PER_DAY = 24 * 60;

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30';
const textareaClass =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

type FormData = {
    patient_id?: number | null;
    doctor_id: string;
    date: string;
    start_time: string;
    end_time: string;
    reason?: string;
};

const initial: FormData = {
    doctor_id: props.appointment?.doctor.id.toString() ?? '',
    date: props.appointment?.date ?? '',
    start_time: props.appointment?.start ?? '',
    end_time: props.appointment?.end ?? '',
};

if (!props.appointment) {
    initial.patient_id = props.patient?.id ?? null;
}

// The reason is only sent by users allowed to write it; the server rejects
// it from anyone else.
if (props.canEditReason) {
    initial.reason = props.appointment?.reason ?? '';
}

const form = useForm<FormData>(initial);

const selectedPatient = computed(
    () => props.appointment?.patient ?? props.patient,
);

watch(
    () => props.patient,
    (patient) => {
        if (!props.appointment) {
            form.patient_id = patient?.id ?? null;
        }
    },
);

function toMinutes(time: string): number | null {
    const match = /^(\d{2}):(\d{2})$/.exec(time);

    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

function fromMinutes(minutes: number): string {
    const hours = Math.floor(minutes / 60);

    return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}`;
}

const duration = computed<number | null>(() => {
    const start = toMinutes(form.start_time);
    const end = toMinutes(form.end_time);

    return start === null || end === null ? null : end - start;
});

let lastDuration = props.appointment?.duration_minutes ?? DEFAULT_DURATION;

function setEndAfter(minutes: number): void {
    const start = toMinutes(form.start_time);

    if (start === null) {
        return;
    }

    // Appointments end on the day they start; leave the end for the user to
    // fix rather than wrapping past midnight.
    form.end_time =
        start + minutes < MINUTES_PER_DAY ? fromMinutes(start + minutes) : '';
}

// Moving the start keeps the length of the appointment.
watch(
    () => form.start_time,
    () => setEndAfter(lastDuration),
);

watch(duration, (minutes) => {
    if (minutes !== null && minutes > 0) {
        lastDuration = minutes;
    }
});

const timeHint = computed<string | null>(() => {
    if (duration.value === null) {
        return null;
    }

    if (duration.value <= 0) {
        return 'The end time must be after the start time.';
    }

    const hours = Math.floor(duration.value / 60);
    const minutes = duration.value % 60;

    return [hours ? `${hours} h` : '', minutes ? `${minutes} min` : '']
        .filter(Boolean)
        .join(' ');
});

function submit(): void {
    form.submit(props.action.method, props.action.url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <form class="space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label>Patient</Label>
            <p v-if="selectedPatient" class="text-sm">
                <span class="font-medium">{{ selectedPatient.full_name }}</span>
                <span class="ml-2 font-mono text-xs text-muted-foreground">
                    {{ selectedPatient.medical_record_number }}
                </span>
            </p>
            <p v-else class="text-sm text-muted-foreground">
                Search for a patient above.
            </p>
            <InputError
                :message="(form.errors as Record<string, string>).patient_id"
            />
        </div>

        <div class="grid gap-2">
            <Label for="doctor_id">Clinician</Label>
            <select
                id="doctor_id"
                v-model="form.doctor_id"
                required
                :class="selectClass"
            >
                <option value="" disabled>Select…</option>
                <option
                    v-for="doctor in doctors"
                    :key="doctor.id"
                    :value="doctor.id.toString()"
                >
                    {{ doctor.name }}
                </option>
            </select>
            <InputError :message="form.errors.doctor_id" />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="date">Date</Label>
                <Input
                    id="date"
                    v-model="form.date"
                    type="date"
                    :min="today"
                    required
                />
                <InputError :message="form.errors.date" />
            </div>

            <div class="grid gap-2">
                <Label for="start_time">Start</Label>
                <Input
                    id="start_time"
                    v-model="form.start_time"
                    type="time"
                    step="300"
                    required
                />
                <InputError :message="form.errors.start_time" />
            </div>

            <div class="grid gap-2">
                <Label for="end_time">End</Label>
                <Input
                    id="end_time"
                    v-model="form.end_time"
                    type="time"
                    step="300"
                    required
                />
                <InputError :message="form.errors.end_time" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span class="text-muted-foreground">Length:</span>
            <Button
                v-for="minutes in DURATIONS"
                :key="minutes"
                type="button"
                size="sm"
                :variant="duration === minutes ? 'default' : 'outline'"
                :disabled="!form.start_time"
                @click="setEndAfter(minutes)"
            >
                {{ minutes }} min
            </Button>
            <span
                v-if="timeHint"
                :class="
                    duration !== null && duration <= 0
                        ? 'text-destructive'
                        : 'text-muted-foreground'
                "
            >
                {{ timeHint }}
            </span>
        </div>
        <p class="text-xs text-muted-foreground">
            Times are in the clinic's timezone ({{ timezone }}).
        </p>

        <div v-if="canEditReason" class="grid gap-2">
            <Label for="reason">Reason for visit (optional)</Label>
            <textarea
                id="reason"
                v-model="form.reason"
                rows="3"
                maxlength="500"
                :class="textareaClass"
            />
            <p class="text-xs text-muted-foreground">
                Visible to clinic staff and the patient. Keep clinical detail in
                the medical record.
            </p>
            <InputError :message="form.errors.reason" />
        </div>

        <InputError :message="(form.errors as Record<string, string>).status" />

        <Button type="submit" :disabled="form.processing">
            {{ submitLabel }}
        </Button>
    </form>
</template>
