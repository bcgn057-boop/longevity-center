<script setup lang="ts">
import type { Patient } from '@/types';

defineProps<{
    patient: Patient;
}>();
</script>

<template>
    <div class="space-y-8">
        <section class="space-y-3">
            <h2 class="text-base font-medium">Personal details</h2>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Medical record number</dt>
                    <dd class="font-mono">
                        {{ patient.medical_record_number ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Sex at birth</dt>
                    <dd>{{ patient.sex_label }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Date of birth</dt>
                    <dd>{{ patient.date_of_birth }} ({{ patient.age }})</dd>
                </div>
            </dl>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Contact</h2>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-muted-foreground">Email</dt>
                    <dd>{{ patient.email || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Phone</dt>
                    <dd>{{ patient.phone || '—' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-muted-foreground">Address</dt>
                    <dd class="whitespace-pre-line">
                        {{ patient.address || '—' }}
                    </dd>
                </div>
            </dl>
        </section>

        <!-- Only rendered when the server sent medical fields at all. -->
        <section v-if="'medical_history' in patient" class="space-y-3">
            <h2 class="text-base font-medium">Medical information</h2>
            <dl class="grid gap-y-3 text-sm">
                <div>
                    <dt class="text-muted-foreground">Medical history</dt>
                    <dd class="whitespace-pre-line">
                        {{ patient.medical_history || '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Allergies</dt>
                    <dd class="whitespace-pre-line">
                        {{ patient.allergies || 'None recorded' }}
                    </dd>
                </div>
            </dl>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Emergency contacts</h2>
            <p
                v-if="patient.emergency_contacts.length === 0"
                class="text-sm text-muted-foreground"
            >
                No emergency contacts.
            </p>
            <ul v-else class="grid gap-3 sm:grid-cols-2">
                <li
                    v-for="(contact, index) in patient.emergency_contacts"
                    :key="index"
                    class="rounded-lg border p-3 text-sm"
                >
                    <div class="font-medium">
                        {{ contact.name }}
                        <span class="font-normal text-muted-foreground">
                            · {{ contact.relationship }}
                        </span>
                    </div>
                    <div>{{ contact.phone }}</div>
                    <div v-if="contact.email">{{ contact.email }}</div>
                </li>
            </ul>
        </section>
    </div>
</template>
