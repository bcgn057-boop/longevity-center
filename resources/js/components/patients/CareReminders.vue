<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { cancel } from '@/routes/care-reminders';
import { store } from '@/routes/patients/care-reminders';
import type { CareReminderItem } from '@/types';

const props = defineProps<{
    patientId: number;
    reminders: CareReminderItem[];
    hasAccount: boolean;
}>();

const textareaClass =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

const form = useForm({ title: '', message: '', date: '', time: '09:00' });
const cancelForm = useForm({});

function submit(): void {
    form.post(store(props.patientId).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function cancelReminder(id: number): void {
    cancelForm.post(cancel(id).url, { preserveScroll: true });
}
</script>

<template>
    <section class="space-y-4">
        <div>
            <h2 class="text-base font-medium">Care reminders</h2>
            <p class="text-sm text-muted-foreground">
                The text is only shown in the patient portal. Email and WhatsApp
                only tell the patient there is a new message.
            </p>
        </div>

        <ul v-if="reminders.length" class="divide-y rounded-lg border text-sm">
            <li
                v-for="reminder in reminders"
                :key="reminder.id"
                class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
            >
                <div class="space-y-1">
                    <div class="font-medium">{{ reminder.title }}</div>
                    <div
                        v-if="reminder.message"
                        class="whitespace-pre-line text-muted-foreground"
                    >
                        {{ reminder.message }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ reminder.status_label }} · due {{ reminder.due }}
                        <span v-if="reminder.created_by">
                            · by {{ reminder.created_by }}
                        </span>
                    </div>
                </div>
                <Button
                    v-if="reminder.status === 'scheduled'"
                    variant="outline"
                    size="sm"
                    :disabled="cancelForm.processing"
                    @click="cancelReminder(reminder.id)"
                >
                    Cancel
                </Button>
            </li>
        </ul>

        <p v-if="!hasAccount" class="text-sm text-muted-foreground">
            Link a portal account to send care reminders.
        </p>

        <form v-else class="space-y-3" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="care-title">Title</Label>
                <Input
                    id="care-title"
                    v-model="form.title"
                    required
                    maxlength="120"
                    placeholder="e.g. Book your follow-up blood test"
                />
                <InputError :message="form.errors.title" />
            </div>
            <div class="grid gap-2">
                <Label for="care-message">Message (optional)</Label>
                <textarea
                    id="care-message"
                    v-model="form.message"
                    rows="3"
                    maxlength="1000"
                    :class="textareaClass"
                />
                <InputError :message="form.errors.message" />
            </div>
            <div class="flex flex-wrap items-end gap-3">
                <div class="grid gap-2">
                    <Label for="care-date">Send on</Label>
                    <Input
                        id="care-date"
                        v-model="form.date"
                        type="date"
                        required
                    />
                    <InputError :message="form.errors.date" />
                </div>
                <div class="grid gap-2">
                    <Label for="care-time">At</Label>
                    <Input
                        id="care-time"
                        v-model="form.time"
                        type="time"
                        step="300"
                        required
                    />
                    <InputError :message="form.errors.time" />
                </div>
                <Button type="submit" :disabled="form.processing">
                    Schedule reminder
                </Button>
            </div>
            <InputError
                :message="(cancelForm.errors as Record<string, string>).status"
            />
        </form>
    </section>
</template>
