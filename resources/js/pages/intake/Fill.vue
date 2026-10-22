<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import ClinicalSection from '@/components/intake/ClinicalSection.vue';
import IntakeSectionEditor from '@/components/intake/IntakeSectionEditor.vue';
import IntakeSummary from '@/components/intake/IntakeSummary.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    complete as completeIntake,
    update as updateIntake,
} from '@/routes/intake-forms';
import {
    index as myIntakeIndex,
    submit as submitMine,
    update as updateMine,
} from '@/routes/my-intake';
import { show as showPatient } from '@/routes/patients';
import type { IntakeForm, IntakeSection } from '@/types';

const props = defineProps<{
    // Who is filling it in: the patient, or a clinician at the clinic.
    mode: 'patient' | 'clinician';
    patient: { id: number; full_name: string } | null;
    form: IntakeForm;
    schema: Record<string, IntakeSection>;
    editable: boolean;
}>();

const patientSections = computed(() =>
    Object.fromEntries(
        Object.entries(props.schema).filter(([key]) => key !== 'clinical'),
    ),
);

// Start from saved answers; rows sections are lists, the rest objects.
const data = useForm<Record<string, any>>(
    Object.fromEntries(
        Object.entries(patientSections.value).map(([key, section]) => {
            const saved = props.form.answers[key];

            return [
                key,
                section.shape === 'rows'
                    ? Array.isArray(saved)
                        ? saved.map((row) => ({ ...row }))
                        : []
                    : JSON.parse(JSON.stringify(saved ?? {})),
            ];
        }),
    ),
);

const errors = () => data.errors as Record<string, string | undefined>;

function save(): void {
    const url =
        props.mode === 'patient'
            ? updateMine(props.form.id).url
            : updateIntake(props.form.id).url;
    data.put(url, { preserveScroll: true });
}

function finish(): void {
    const url =
        props.mode === 'patient'
            ? submitMine(props.form.id).url
            : completeIntake(props.form.id).url;
    data.post(url);
}
</script>

<template>
    <Head title="Health questionnaire" />

    <div class="flex h-full max-w-5xl flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-xl font-semibold">Health questionnaire</h1>
                <p v-if="patient" class="text-sm">
                    <Link
                        :href="
                            showPatient(
                                { patient: patient.id },
                                { query: { tab: 'measurements' } },
                            )
                        "
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ patient.full_name }}
                    </Link>
                </p>
                <p class="text-sm text-muted-foreground">
                    <template v-if="editable && mode === 'patient'">
                        Answer what you can and save as you go. Leave anything
                        you're unsure about blank. Your answers go only to your
                        care team.
                    </template>
                    <template v-else-if="editable">
                        Filling in with the patient at the clinic.
                    </template>
                    <template v-else-if="form.submitted_at">
                        Submitted {{ form.submitted_at }}.
                    </template>
                </p>
            </div>
            <Badge variant="outline">{{ form.status_label }}</Badge>
        </div>

        <form v-if="editable" class="space-y-6" @submit.prevent="save">
            <IntakeSectionEditor
                v-for="(section, key) in patientSections"
                :key="key"
                v-model="data[key]"
                :section-key="String(key)"
                :section="section"
                :errors="errors()"
            />
            <InputError :message="errors().intake" />
            <div
                class="sticky bottom-0 flex flex-wrap gap-2 border-t bg-background py-3"
            >
                <Button
                    type="submit"
                    variant="outline"
                    :disabled="data.processing"
                >
                    Save progress
                </Button>
                <Button
                    type="button"
                    :disabled="data.processing"
                    @click="finish"
                >
                    {{
                        mode === 'patient'
                            ? 'Submit to my clinician'
                            : 'Mark as complete'
                    }}
                </Button>
            </div>
        </form>

        <template v-else>
            <IntakeSummary :schema="patientSections" :answers="form.answers" />
            <ClinicalSection
                v-if="schema.clinical && Object.keys(form.clinical).length"
                :form="form"
                :section="schema.clinical"
                :editable="false"
            />
            <Link
                v-if="mode === 'patient'"
                :href="myIntakeIndex()"
                class="text-sm underline underline-offset-4"
            >
                All your questionnaires
            </Link>
        </template>
    </div>
</template>
