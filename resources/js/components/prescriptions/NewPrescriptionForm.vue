<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Plus, X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import ProtocolItemFields from '@/components/prescriptions/ProtocolItemFields.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { emptyItemRow, textareaClass } from '@/lib/prescriptions';
import { store } from '@/routes/patients/prescriptions';
import type { PrescriptionOptions, ProtocolItemRow } from '@/types';

const props = defineProps<{
    patientId: number;
    options: PrescriptionOptions;
    today: string;
}>();

const emit = defineEmits<{ done: [] }>();

const MAX_ITEMS = 30;

const form = useForm<{
    title: string;
    notes: string;
    starts_on: string;
    ends_on: string;
    items: ProtocolItemRow[];
}>({
    title: '',
    notes: '',
    starts_on: props.today,
    ends_on: '',
    items: [emptyItemRow()],
});

function addItem(): void {
    if (form.items.length < MAX_ITEMS) {
        form.items.push(emptyItemRow());
    }
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        ends_on: data.ends_on || null,
    })).post(store(props.patientId).url, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('done');
        },
    });
}
</script>

<template>
    <form class="space-y-6 rounded-lg border p-4" @submit.prevent="submit">
        <div>
            <h2 class="text-base font-medium">New prescription</h2>
            <p class="text-sm text-muted-foreground">
                The patient sees each item's name, dose, instructions and how
                often to follow it. Your notes are for clinic staff only.
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2 sm:col-span-2">
                <Label for="new-title">Title</Label>
                <Input
                    id="new-title"
                    v-model="form.title"
                    maxlength="120"
                    required
                    placeholder="e.g. Metabolic health protocol"
                />
                <InputError :message="form.errors.title" />
            </div>
            <div class="grid gap-2">
                <Label for="new-starts">Starts</Label>
                <Input
                    id="new-starts"
                    v-model="form.starts_on"
                    type="date"
                    required
                />
                <InputError :message="form.errors.starts_on" />
            </div>
            <div class="grid gap-2">
                <Label for="new-ends">Ends (optional)</Label>
                <Input
                    id="new-ends"
                    v-model="form.ends_on"
                    type="date"
                    :min="form.starts_on"
                />
                <InputError :message="form.errors.ends_on" />
            </div>
            <div class="grid gap-2 sm:col-span-2">
                <Label for="new-notes">Clinical notes (staff only)</Label>
                <textarea
                    id="new-notes"
                    v-model="form.notes"
                    rows="3"
                    maxlength="2000"
                    :class="textareaClass"
                />
                <InputError :message="form.errors.notes" />
            </div>
        </div>

        <div class="space-y-3">
            <h3 class="text-sm font-medium">Items</h3>
            <InputError :message="form.errors.items" />
            <div
                v-for="(item, index) in form.items"
                :key="index"
                class="relative rounded-md border bg-muted/20 p-3"
            >
                <Button
                    v-if="form.items.length > 1"
                    type="button"
                    variant="ghost"
                    size="icon"
                    class="absolute top-1 right-1 size-7"
                    :aria-label="`Remove item ${index + 1}`"
                    @click="form.items.splice(index, 1)"
                >
                    <X class="size-4" />
                </Button>
                <ProtocolItemFields
                    v-model="form.items[index]"
                    :options="options"
                    :errors="form.errors as Record<string, string | undefined>"
                    :error-prefix="`items.${index}.`"
                    :id-prefix="`new-item-${index}`"
                />
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="form.items.length >= MAX_ITEMS"
                @click="addItem"
            >
                <Plus class="size-4" /> Add another item
            </Button>
        </div>

        <div class="flex gap-2">
            <Button type="submit" :disabled="form.processing">
                Issue prescription
            </Button>
            <Button type="button" variant="ghost" @click="emit('done')">
                Cancel
            </Button>
        </div>
    </form>
</template>
