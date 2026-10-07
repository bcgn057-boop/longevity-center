<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Patient, SelectOption } from '@/types';

const props = defineProps<{
    patient?: Patient;
    sexes: SelectOption[];
    canEditMedical: boolean;
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
}>();

const MAX_CONTACTS = 3;

const textareaClass =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

type ContactFormData = {
    name: string;
    relationship: string;
    phone: string;
    email: string;
};

type FormData = {
    first_name: string;
    last_name: string;
    sex: string;
    date_of_birth: string;
    email: string;
    phone: string;
    address: string;
    medical_history?: string;
    allergies?: string;
    emergency_contacts: ContactFormData[];
};

const initial: FormData = {
    first_name: props.patient?.first_name ?? '',
    last_name: props.patient?.last_name ?? '',
    sex: props.patient?.sex ?? '',
    date_of_birth: props.patient?.date_of_birth ?? '',
    email: props.patient?.email ?? '',
    phone: props.patient?.phone ?? '',
    address: props.patient?.address ?? '',
    emergency_contacts: (props.patient?.emergency_contacts ?? []).map(
        (contact) => ({ ...contact, email: contact.email ?? '' }),
    ),
};

// Medical fields are only sent by users allowed to write them; the server
// rejects them from anyone else.
if (props.canEditMedical) {
    initial.medical_history = props.patient?.medical_history ?? '';
    initial.allergies = props.patient?.allergies ?? '';
}

const form = useForm<FormData>(initial);

const today = new Date().toISOString().slice(0, 10);

function addContact(): void {
    if (form.emergency_contacts.length < MAX_CONTACTS) {
        form.emergency_contacts.push({
            name: '',
            relationship: '',
            phone: '',
            email: '',
        });
    }
}

function removeContact(index: number): void {
    form.emergency_contacts.splice(index, 1);
}

function contactError(index: number, field: string): string | undefined {
    return (form.errors as Record<string, string>)[
        `emergency_contacts.${index}.${field}`
    ];
}

function submit(): void {
    form.submit(props.action.method, props.action.url, {
        preserveScroll: true,
    });
}
</script>

<template>
    <form class="space-y-8" @submit.prevent="submit">
        <section class="space-y-4">
            <h2 class="text-base font-medium">Personal details</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="first_name">First name</Label>
                    <Input
                        id="first_name"
                        v-model="form.first_name"
                        required
                        maxlength="100"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.first_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="last_name">Last name</Label>
                    <Input
                        id="last_name"
                        v-model="form.last_name"
                        required
                        maxlength="100"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.last_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="sex">Sex at birth</Label>
                    <select
                        id="sex"
                        v-model="form.sex"
                        required
                        class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30"
                    >
                        <option value="" disabled>Select…</option>
                        <option
                            v-for="option in sexes"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </select>
                    <InputError :message="form.errors.sex" />
                </div>

                <div class="grid gap-2">
                    <Label for="date_of_birth">Date of birth</Label>
                    <Input
                        id="date_of_birth"
                        v-model="form.date_of_birth"
                        type="date"
                        min="1900-01-02"
                        :max="today"
                        required
                    />
                    <InputError :message="form.errors.date_of_birth" />
                </div>
            </div>
        </section>

        <section class="space-y-4">
            <h2 class="text-base font-medium">Contact</h2>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="email">Email</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        type="tel"
                        placeholder="+14155550123"
                        autocomplete="off"
                    />
                    <InputError :message="form.errors.phone" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="address">Address</Label>
                    <textarea
                        id="address"
                        v-model="form.address"
                        rows="2"
                        maxlength="500"
                        :class="textareaClass"
                    />
                    <InputError :message="form.errors.address" />
                </div>
            </div>
        </section>

        <section v-if="canEditMedical" class="space-y-4">
            <h2 class="text-base font-medium">Medical information</h2>

            <div class="grid gap-2">
                <Label for="medical_history">Medical history</Label>
                <textarea
                    id="medical_history"
                    v-model="form.medical_history"
                    rows="5"
                    maxlength="10000"
                    :class="textareaClass"
                />
                <InputError :message="form.errors.medical_history" />
            </div>

            <div class="grid gap-2">
                <Label for="allergies">Allergies</Label>
                <textarea
                    id="allergies"
                    v-model="form.allergies"
                    rows="2"
                    maxlength="2000"
                    :class="textareaClass"
                />
                <InputError :message="form.errors.allergies" />
            </div>
        </section>

        <section class="space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-medium">Emergency contacts</h2>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="form.emergency_contacts.length >= MAX_CONTACTS"
                    @click="addContact"
                >
                    Add contact
                </Button>
            </div>

            <p
                v-if="form.emergency_contacts.length === 0"
                class="text-sm text-muted-foreground"
            >
                No emergency contacts.
            </p>
            <InputError :message="form.errors.emergency_contacts" />

            <div
                v-for="(contact, index) in form.emergency_contacts"
                :key="index"
                class="grid gap-4 rounded-lg border p-4 sm:grid-cols-2"
            >
                <div class="grid gap-2">
                    <Label :for="`contact-${index}-name`">Name</Label>
                    <Input
                        :id="`contact-${index}-name`"
                        v-model="contact.name"
                        required
                        maxlength="100"
                    />
                    <InputError :message="contactError(index, 'name')" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`contact-${index}-relationship`">
                        Relationship
                    </Label>
                    <Input
                        :id="`contact-${index}-relationship`"
                        v-model="contact.relationship"
                        required
                        maxlength="50"
                    />
                    <InputError
                        :message="contactError(index, 'relationship')"
                    />
                </div>

                <div class="grid gap-2">
                    <Label :for="`contact-${index}-phone`">Phone</Label>
                    <Input
                        :id="`contact-${index}-phone`"
                        v-model="contact.phone"
                        type="tel"
                        required
                        placeholder="+14155550123"
                    />
                    <InputError :message="contactError(index, 'phone')" />
                </div>

                <div class="grid gap-2">
                    <Label :for="`contact-${index}-email`">Email</Label>
                    <Input
                        :id="`contact-${index}-email`"
                        v-model="contact.email"
                        type="email"
                    />
                    <InputError :message="contactError(index, 'email')" />
                </div>

                <div class="sm:col-span-2">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        @click="removeContact(index)"
                    >
                        Remove contact
                    </Button>
                </div>
            </div>
        </section>

        <Button type="submit" :disabled="form.processing">
            {{ submitLabel }}
        </Button>
    </form>
</template>
