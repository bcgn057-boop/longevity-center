<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import ConsentCard from '@/components/ConsentCard.vue';
import LabRequisitions from '@/components/labs/LabRequisitions.vue';
import CareReminders from '@/components/patients/CareReminders.vue';
import PatientDetails from '@/components/patients/PatientDetails.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create as bookAppointment } from '@/routes/appointments';
import {
    create as createLabReport,
    show as showLabReport,
} from '@/routes/labs';
import { destroy, edit, index } from '@/routes/patients';
import { labHistory } from '@/routes/patients';
import { show as assessmentsShow } from '@/routes/patients/assessments';
import { index as prescriptionsIndex } from '@/routes/patients/prescriptions';
import {
    destroy as unlinkAccount,
    store as linkAccount,
} from '@/routes/patients/account';
import {
    destroy as withdrawConsent,
    store as recordConsent,
} from '@/routes/patients/consent';
import type {
    CareReminderItem,
    ConsentChannel,
    LabPartnerOption,
    LabReportSummary,
    LabRequisitionRow,
    Patient,
} from '@/types';

const props = defineProps<{
    patient: Patient;
    consent: ConsentChannel[] | null;
    careReminders: CareReminderItem[] | null;
    labReports: LabReportSummary[] | null;
    labRequisitions: LabRequisitionRow[] | null;
    labPartners: LabPartnerOption[];
    can: {
        update: boolean;
        archive: boolean;
        linkAccount: boolean;
        viewMedical: boolean;
        bookAppointment: boolean;
        manageConsent: boolean;
        manageCareReminders: boolean;
        addLabReport: boolean;
        viewPrescriptions: boolean;
        orderLabTests: boolean;
        viewAssessments: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: index() }],
    },
});

const linkForm = useForm({ email: '' });
const unlinkForm = useForm({});
const archiveForm = useForm({});

function submitLink(): void {
    linkForm.post(linkAccount(props.patient.id).url, {
        preserveScroll: true,
        onSuccess: () => linkForm.reset(),
    });
}

function submitUnlink(): void {
    unlinkForm.delete(unlinkAccount(props.patient.id).url, {
        preserveScroll: true,
    });
}

function submitArchive(): void {
    archiveForm.delete(destroy(props.patient.id).url);
}
</script>

<template>
    <Head :title="patient.full_name" />

    <div class="flex h-full max-w-4xl flex-1 flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">{{ patient.full_name }}</h1>
                <p class="font-mono text-sm text-muted-foreground">
                    {{ patient.medical_record_number }}
                </p>
                <p
                    v-if="!can.viewMedical"
                    class="mt-1 text-sm text-muted-foreground"
                >
                    Medical information is only visible to doctors and
                    practitioners.
                </p>
            </div>

            <div class="flex gap-2">
                <Button v-if="can.bookAppointment" variant="outline" as-child>
                    <Link
                        :href="
                            bookAppointment({
                                query: { patient_id: patient.id },
                            })
                        "
                    >
                        Book appointment
                    </Link>
                </Button>
                <Button v-if="can.viewAssessments" variant="outline" as-child>
                    <Link :href="assessmentsShow(patient.id)"
                        >Measurements</Link
                    >
                </Button>
                <Button v-if="can.viewPrescriptions" variant="outline" as-child>
                    <Link :href="prescriptionsIndex(patient.id)">
                        Prescriptions
                    </Link>
                </Button>
                <Button v-if="can.update" variant="outline" as-child>
                    <Link :href="edit(patient.id)">Edit</Link>
                </Button>

                <Dialog v-if="can.archive">
                    <DialogTrigger as-child>
                        <Button variant="destructive">Archive</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                Archive {{ patient.full_name }}?
                            </DialogTitle>
                            <DialogDescription>
                                The record is hidden from patient lists but kept
                                permanently, as medical records must be
                                retained.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button
                                variant="destructive"
                                :disabled="archiveForm.processing"
                                @click="submitArchive"
                            >
                                Archive patient
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </div>

        <PatientDetails :patient="patient" />

        <section
            v-if="can.manageConsent && consent && consent.length"
            class="space-y-3"
        >
            <div>
                <h2 class="text-base font-medium">Reminder consent</h2>
                <p class="text-sm text-muted-foreground">
                    In-app reminders need no consent. Email and WhatsApp are
                    only used once the patient agrees.
                </p>
            </div>
            <ConsentCard
                v-for="channel in consent"
                :key="channel.channel"
                :consent="channel"
                :grant-url="
                    recordConsent({
                        patient: patient.id,
                        channel: channel.channel,
                    }).url
                "
                :withdraw-url="
                    withdrawConsent({
                        patient: patient.id,
                        channel: channel.channel,
                    }).url
                "
                field="confirmed"
                intro="Read this to the patient and record their answer:"
                checkbox-label="The patient has agreed to this wording."
            />
        </section>

        <section v-if="labReports" class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-base font-medium">Lab reports</h2>
                <Button
                    v-if="can.orderLabTests && labReports.length"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="labHistory(patient.id)">
                        All labs side by side
                    </Link>
                </Button>
                <Button
                    v-if="can.addLabReport"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="
                            createLabReport({
                                query: { patient_id: patient.id },
                            })
                        "
                    >
                        Add lab report
                    </Link>
                </Button>
            </div>
            <p
                v-if="labReports.length === 0"
                class="text-sm text-muted-foreground"
            >
                No lab reports yet.
            </p>
            <ul v-else class="divide-y rounded-lg border text-sm">
                <li
                    v-for="report in labReports"
                    :key="report.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-2"
                >
                    <Link
                        :href="showLabReport(report.id)"
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ report.collected }}
                    </Link>
                    <span class="text-muted-foreground">
                        {{ report.source }} · {{ report.results_count }} results
                        ·
                        {{ report.status_label }}
                    </span>
                </li>
            </ul>
        </section>

        <LabRequisitions
            v-if="labRequisitions"
            :patient-id="patient.id"
            :requisitions="labRequisitions"
            :lab-partners="labPartners"
            :can-order="can.orderLabTests"
        />

        <CareReminders
            v-if="can.manageCareReminders && careReminders"
            :patient-id="patient.id"
            :reminders="careReminders"
            :has-account="patient.account !== null"
        />

        <section v-if="can.linkAccount" class="space-y-3">
            <h2 class="text-base font-medium">Patient portal account</h2>

            <div
                v-if="patient.account"
                class="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3 text-sm"
            >
                <div>
                    Linked to
                    <span class="font-medium">{{ patient.account.name }}</span>
                    ({{ patient.account.email }})
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="unlinkForm.processing"
                    @click="submitUnlink"
                >
                    Unlink
                </Button>
            </div>

            <form
                v-else
                class="flex flex-wrap items-end gap-3"
                @submit.prevent="submitLink"
            >
                <div class="grid gap-1.5">
                    <Label for="link-email">Account email</Label>
                    <Input
                        id="link-email"
                        v-model="linkForm.email"
                        type="email"
                        required
                        class="w-72"
                    />
                    <InputError :message="linkForm.errors.email" />
                </div>
                <Button type="submit" :disabled="linkForm.processing">
                    Link account
                </Button>
                <p class="w-full text-xs text-muted-foreground">
                    The account must have the Patient role and a verified email
                    address.
                </p>
            </form>
        </section>
    </div>
</template>
