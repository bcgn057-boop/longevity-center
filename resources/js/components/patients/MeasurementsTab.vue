<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { Send } from '@lucide/vue';
import AssessmentFormCard from '@/components/assessments/AssessmentFormCard.vue';
import AssessmentSummary from '@/components/assessments/AssessmentSummary.vue';
import InputError from '@/components/InputError.vue';
import ClinicalSection from '@/components/intake/ClinicalSection.vue';
import IntakeSummary from '@/components/intake/IntakeSummary.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit as editIntake } from '@/routes/intake-forms';
import { store as storeAssessment } from '@/routes/patients/assessments';
import {
    send as sendIntake,
    start as startIntake,
} from '@/routes/patients/intake';
import type {
    AssessmentEntry,
    AssessmentForm,
    IntakeForm,
    IntakeSection,
    LatestMeasurement,
} from '@/types';

export type MeasurementsData = {
    forms: AssessmentForm[];
    latest: LatestMeasurement[];
    history: AssessmentEntry[];
    today: string;
    intake: {
        current: IntakeForm | null;
        previous: {
            id: number;
            status_label: string;
            submitted_at: string | null;
        }[];
        schema: Record<string, IntakeSection>;
        hasPortalAccount: boolean;
    };
};

const props = defineProps<{
    patientId: number;
    data: MeasurementsData;
    canManageIntake: boolean;
}>();

const sendForm = useForm({});
const startForm = useForm({});

const patientSections = Object.fromEntries(
    Object.entries(props.data.intake.schema).filter(
        ([key]) => key !== 'clinical',
    ),
);
</script>

<template>
    <div class="space-y-8">
        <section class="space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-medium">Health questionnaire</h2>
                    <p class="text-sm text-muted-foreground">
                        Medical history and lifestyle, filled in by the patient
                        through a secure link or with you at the clinic.
                    </p>
                </div>
                <div v-if="canManageIntake" class="flex flex-wrap gap-2">
                    <Button
                        :disabled="
                            sendForm.processing || !data.intake.hasPortalAccount
                        "
                        :title="
                            data.intake.hasPortalAccount
                                ? undefined
                                : 'Link a portal account first'
                        "
                        @click="
                            sendForm.post(sendIntake(patientId).url, {
                                preserveScroll: true,
                            })
                        "
                    >
                        <Send class="size-4" />
                        {{
                            data.intake.current?.status === 'sent_to_patient'
                                ? 'Resend form to patient'
                                : 'Send form to patient'
                        }}
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="startForm.processing"
                        @click="startForm.post(startIntake(patientId).url)"
                    >
                        Fill in at the clinic
                    </Button>
                </div>
            </div>
            <InputError
                :message="(sendForm.errors as Record<string, string>).intake"
            />
            <p
                v-if="canManageIntake && !data.intake.hasPortalAccount"
                class="text-xs text-muted-foreground"
            >
                To send the form, link the patient's portal account on the
                Overview tab. You can still fill it in at the clinic.
            </p>

            <p
                v-if="!data.intake.current"
                class="text-sm text-muted-foreground"
            >
                No questionnaire yet.
            </p>
            <template v-else>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <Badge variant="outline">{{
                        data.intake.current.status_label
                    }}</Badge>
                    <span
                        v-if="data.intake.current.sent_at"
                        class="text-muted-foreground"
                    >
                        Sent {{ data.intake.current.sent_at }}
                        <template v-if="data.intake.current.doctor">
                            by {{ data.intake.current.doctor }}</template
                        ><template v-if="data.intake.current.link_expires">
                            · link works until
                            {{ data.intake.current.link_expires }}</template
                        >
                    </span>
                    <span
                        v-if="data.intake.current.submitted_at"
                        class="text-muted-foreground"
                    >
                        · submitted {{ data.intake.current.submitted_at }}
                    </span>
                    <Button
                        v-if="
                            canManageIntake &&
                            data.intake.current.status !== 'completed'
                        "
                        variant="link"
                        size="sm"
                        as-child
                    >
                        <Link :href="editIntake(data.intake.current.id)">
                            Open the form
                        </Link>
                    </Button>
                </div>

                <IntakeSummary
                    v-if="data.intake.current.status === 'completed'"
                    :schema="patientSections"
                    :answers="data.intake.current.answers"
                />
                <ClinicalSection
                    v-if="data.intake.schema.clinical"
                    :form="data.intake.current"
                    :section="data.intake.schema.clinical"
                    :editable="canManageIntake"
                />
            </template>

            <details v-if="data.intake.previous.length" class="text-sm">
                <summary class="cursor-pointer text-muted-foreground">
                    Earlier questionnaires ({{ data.intake.previous.length }})
                </summary>
                <ul class="mt-2 space-y-1 pl-4">
                    <li v-for="form in data.intake.previous" :key="form.id">
                        <Link
                            :href="editIntake(form.id)"
                            class="underline-offset-4 hover:underline"
                        >
                            {{ form.submitted_at ?? 'Not submitted' }}
                        </Link>
                        · {{ form.status_label }}
                    </li>
                </ul>
            </details>
        </section>

        <AssessmentSummary :latest="data.latest" :history="data.history" />

        <div class="grid gap-6 lg:grid-cols-2">
            <AssessmentFormCard
                v-for="form in data.forms"
                :key="form.kind"
                :form="form"
                :url="storeAssessment(patientId).url"
                :today="data.today"
                :hint="
                    form.kind === 'at_center'
                        ? 'Only doctors and practitioners can record these, here in the clinic.'
                        : 'The patient can also enter these from their portal.'
                "
            />
        </div>
    </div>
</template>
