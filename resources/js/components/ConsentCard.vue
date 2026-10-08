<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import type { ConsentChannel } from '@/types';

const props = defineProps<{
    consent: ConsentChannel;
    grantUrl: string;
    withdrawUrl: string;
    // "agree" for the patient themselves, "confirmed" for staff.
    field: 'agree' | 'confirmed';
    checkboxLabel: string;
    // Staff read the wording to the patient; patients read it themselves.
    intro?: string;
}>();

const grantForm = useForm<Record<string, boolean>>({ [props.field]: false });
const withdrawForm = useForm({});

function grant(): void {
    grantForm.post(props.grantUrl, {
        preserveScroll: true,
        onSuccess: () => grantForm.reset(),
    });
}

function withdraw(): void {
    withdrawForm.delete(props.withdrawUrl, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-3 rounded-lg border p-4 text-sm">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <div class="font-medium">{{ consent.label }} reminders</div>
                <div class="text-muted-foreground">
                    {{ consent.destination ?? 'No address on record' }}
                </div>
            </div>
            <span
                class="rounded-full px-2 py-0.5 text-xs"
                :class="
                    consent.active && !consent.destination_changed
                        ? 'bg-primary/10 text-primary'
                        : 'bg-muted text-muted-foreground'
                "
            >
                {{
                    consent.active
                        ? consent.destination_changed
                            ? 'Paused: address changed'
                            : 'On'
                        : 'Off'
                }}
            </span>
        </div>

        <p v-if="consent.active" class="text-xs text-muted-foreground">
            Agreed {{ consent.consented_at }} ({{
                consent.source === 'self'
                    ? 'by the patient'
                    : 'recorded by staff'
            }}, wording version {{ consent.consent_version }}).
        </p>

        <p v-if="consent.destination_changed" class="text-xs text-destructive">
            The
            {{ consent.channel === 'mail' ? 'email address' : 'phone number' }}
            has changed since consent was given. Nothing is sent until consent
            is given again for the new address.
        </p>

        <template v-if="!consent.active || consent.destination_changed">
            <p v-if="intro" class="text-xs text-muted-foreground">
                {{ intro }}
            </p>
            <blockquote class="border-l-2 pl-3 text-muted-foreground">
                {{ consent.wording }}
            </blockquote>
            <div class="flex items-start gap-2">
                <Checkbox
                    :id="`${field}-${consent.channel}`"
                    v-model="grantForm[field]"
                />
                <Label :for="`${field}-${consent.channel}`" class="font-normal">
                    {{ checkboxLabel }}
                </Label>
            </div>
            <InputError :message="grantForm.errors[field]" />
            <Button
                size="sm"
                :disabled="grantForm.processing || !consent.destination"
                @click="grant"
            >
                Turn on {{ consent.label }} reminders
            </Button>
        </template>

        <Button
            v-if="consent.active"
            variant="outline"
            size="sm"
            :disabled="withdrawForm.processing"
            @click="withdraw"
        >
            Turn off {{ consent.label }} reminders
        </Button>
    </div>
</template>
