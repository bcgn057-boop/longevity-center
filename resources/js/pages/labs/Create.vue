<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ResultRowsEditor from '@/components/labs/ResultRowsEditor.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { emptyResultRow } from '@/lib/labs';
import { index, store } from '@/routes/labs';
import type { BiomarkerGroup, PatientOption, ResultRow } from '@/types';

const props = defineProps<{
    patient: PatientOption | null;
    patientOptions: PatientOption[];
    catalog: BiomarkerGroup[];
    timezone: string;
    now: { date: string; time: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lab results', href: index() }],
    },
});

const MIN_SEARCH = 2;

const selected = ref<PatientOption | null>(props.patient);
const search = ref('');

const form = useForm<{
    patient_id: number | null;
    collected_date: string;
    collected_time: string;
    lab_name: string;
    notes: string;
    // The lab's original report, sent as multipart form data when present.
    attachment: File | null;
    results: ResultRow[];
}>({
    patient_id: props.patient?.id ?? null,
    collected_date: props.now.date,
    collected_time: '08:00',
    lab_name: '',
    notes: '',
    attachment: null,
    results: [emptyResultRow()],
});

let timer: ReturnType<typeof setTimeout> | undefined;

// Each search is a partial reload of the matching patients only, recorded
// in the audit log like any other patient search.
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

function choose(patient: PatientOption | null): void {
    selected.value = patient;
    form.patient_id = patient?.id ?? null;
    search.value = '';
}

const MAX_PDF_BYTES = 10 * 1024 * 1024;
const attachmentError = ref<string | null>(null);

function chooseAttachment(event: Event): void {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;

    // Checked here for a quick answer; the server checks again.
    attachmentError.value =
        file && file.size > MAX_PDF_BYTES
            ? 'The PDF must be 10 MB or smaller.'
            : null;
    form.attachment = file;
}

function submit(): void {
    form.post(store().url, { preserveScroll: true });
}

const errors = (): Record<string, string | undefined> =>
    form.errors as Record<string, string | undefined>;
</script>

<template>
    <Head title="Enter lab report" />

    <form
        class="flex h-full max-w-6xl flex-1 flex-col gap-8 p-4"
        @submit.prevent="submit"
    >
        <h1 class="text-xl font-semibold">Enter lab report</h1>

        <section class="space-y-2">
            <Label for="patient-search">Patient</Label>
            <div v-if="selected" class="flex items-center gap-3 text-sm">
                <span class="font-medium">{{ selected.full_name }}</span>
                <span class="font-mono text-xs text-muted-foreground">
                    {{ selected.medical_record_number }}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="choose(null)"
                >
                    Change
                </Button>
            </div>
            <div v-else class="grid gap-2">
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
            <InputError :message="form.errors.patient_id" />
        </section>

        <section class="grid gap-4 sm:grid-cols-3">
            <div class="grid gap-2">
                <Label for="collected_date">Collected on</Label>
                <Input
                    id="collected_date"
                    v-model="form.collected_date"
                    type="date"
                    :max="now.date"
                    required
                />
                <InputError :message="form.errors.collected_date" />
            </div>
            <div class="grid gap-2">
                <Label for="collected_time">At ({{ timezone }})</Label>
                <Input
                    id="collected_time"
                    v-model="form.collected_time"
                    type="time"
                    required
                />
                <InputError :message="form.errors.collected_time" />
            </div>
            <div class="grid gap-2">
                <Label for="lab_name">Lab (optional)</Label>
                <Input id="lab_name" v-model="form.lab_name" maxlength="120" />
                <InputError :message="form.errors.lab_name" />
            </div>
            <div class="grid gap-2 sm:col-span-3">
                <Label for="notes">Notes (optional)</Label>
                <Input
                    id="notes"
                    v-model="form.notes"
                    maxlength="2000"
                    placeholder="e.g. Patient was not fasting"
                />
                <InputError :message="form.errors.notes" />
            </div>
            <div class="grid gap-2 sm:col-span-3">
                <Label for="attachment">Original report (PDF, optional)</Label>
                <Input
                    id="attachment"
                    type="file"
                    accept=".pdf,application/pdf"
                    aria-describedby="attachment-help"
                    class="cursor-pointer file:mr-3 file:cursor-pointer"
                    @change="chooseAttachment"
                />
                <p id="attachment-help" class="text-xs text-muted-foreground">
                    Up to 10 MB. Stored encrypted and only available to lab
                    staff, doctors and practitioners.
                </p>
                <InputError
                    :message="attachmentError ?? form.errors.attachment"
                />
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Results</h2>
            <ResultRowsEditor
                v-model="form.results"
                :catalog="catalog"
                :errors="errors()"
            />
        </section>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                Save and send for review
            </Button>
            <span class="text-xs text-muted-foreground">
                The patient sees nothing until a doctor or practitioner releases
                the report.
            </span>
        </div>
    </form>
</template>
