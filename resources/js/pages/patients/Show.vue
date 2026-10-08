<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
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
import { destroy, edit, index } from '@/routes/patients';
import {
    destroy as unlinkAccount,
    store as linkAccount,
} from '@/routes/patients/account';
import type { Patient } from '@/types';

const props = defineProps<{
    patient: Patient;
    can: {
        update: boolean;
        archive: boolean;
        linkAccount: boolean;
        viewMedical: boolean;
        bookAppointment: boolean;
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
