<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ConsentCard from '@/components/ConsentCard.vue';
import Heading from '@/components/Heading.vue';
import { edit, grant, withdraw } from '@/routes/notification-settings';
import type { ConsentChannel } from '@/types';

defineProps<{
    linked: boolean;
    channels: ConsentChannel[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notification settings', href: edit() }],
    },
});
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Reminders"
            description="You always get reminders in the patient portal. You can also choose to get them by email or WhatsApp."
        />

        <p v-if="!linked" class="text-sm text-muted-foreground">
            Your account is not linked to a patient record yet. Please contact
            the clinic.
        </p>

        <template v-else>
            <p
                v-if="channels.length === 0"
                class="text-sm text-muted-foreground"
            >
                Email and WhatsApp reminders are not available at the moment.
            </p>

            <ConsentCard
                v-for="consent in channels"
                :key="consent.channel"
                :consent="consent"
                :grant-url="grant(consent.channel).url"
                :withdraw-url="withdraw(consent.channel).url"
                field="agree"
                checkbox-label="I have read this and agree."
            />

            <p class="text-xs text-muted-foreground">
                To change your email address or phone number, please contact the
                clinic.
            </p>
        </template>
    </div>
</template>
