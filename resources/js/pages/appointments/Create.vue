<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppointmentForm from '@/components/appointments/AppointmentForm.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, store } from '@/routes/appointments';
import type {
    AppointmentTypeOption,
    DoctorOption,
    PatientOption,
} from '@/types';

const props = defineProps<{
    patient: PatientOption | null;
    patientOptions: PatientOption[];
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

const selected = ref<PatientOption | null>(props.patient);
const search = ref('');
const MIN_SEARCH = 2;

let timer: ReturnType<typeof setTimeout> | undefined;

// Each search is a partial reload of the matching patients only, and the
// server records it in the audit log like any other patient search.
watch(search, (term) => {
    clearTimeout(timer);

    if (term.trim().length < MIN_SEARCH) {
        return;
    }

    timer = setTimeout(() => {
        router.reload({
            only: ['patientOptions'],
            data: { patient_search: term.trim() },
        });
    }, 300);
});

function choose(patient: PatientOption): void {
    selected.value = patient;
    search.value = '';
}
</script>

<template>
    <Head title="Book appointment" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Book appointment</h1>

        <section class="space-y-2">
            <div v-if="selected" class="flex items-center gap-3">
                <Button
                    variant="outline"
                    size="sm"
                    type="button"
                    @click="selected = null"
                >
                    Change patient
                </Button>
            </div>

            <div v-else class="grid gap-2">
                <Label for="patient-search">Find patient</Label>
                <Input
                    id="patient-search"
                    v-model="search"
                    placeholder="Name or record number"
                    maxlength="100"
                    autocomplete="off"
                    class="w-80"
                />
                <ul
                    v-if="search.trim().length >= MIN_SEARCH"
                    class="w-80 divide-y rounded-md border text-sm"
                >
                    <li
                        v-if="patientOptions.length === 0"
                        class="px-3 py-2 text-muted-foreground"
                    >
                        No matching patients.
                    </li>
                    <li v-for="option in patientOptions" :key="option.id">
                        <button
                            type="button"
                            class="flex w-full justify-between px-3 py-2 text-left hover:bg-muted/50"
                            @click="choose(option)"
                        >
                            <span>{{ option.full_name }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                            >
                                {{ option.medical_record_number }}
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </section>

        <AppointmentForm
            :patient="selected"
            :doctors="doctors"
            :types="types"
            :can-edit-reason="canEditReason"
            :timezone="timezone"
            :today="today"
            :action="store()"
            submit-label="Book appointment"
        />
    </div>
</template>
