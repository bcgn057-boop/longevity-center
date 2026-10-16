<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AssessmentForm } from '@/types';

const props = defineProps<{
    form: AssessmentForm;
    url: string;
    today: string;
    hint?: string;
}>();

const textareaClass =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

const data = useForm<Record<string, string>>({
    kind: props.form.kind,
    measured_on: props.today,
    notes: '',
    ...Object.fromEntries(props.form.fields.map((field) => [field.field, ''])),
});

const errors = () => data.errors as Record<string, string | undefined>;

function submit(): void {
    data.post(props.url, {
        preserveScroll: true,
        onSuccess: () => data.reset(),
    });
}
</script>

<template>
    <form class="space-y-4 rounded-lg border p-4" @submit.prevent="submit">
        <div>
            <h2 class="text-base font-medium">{{ form.label }}</h2>
            <p v-if="hint" class="text-sm text-muted-foreground">{{ hint }}</p>
        </div>

        <div class="grid gap-2 sm:w-48">
            <Label :for="`${form.kind}-date`">Date measured</Label>
            <Input
                :id="`${form.kind}-date`"
                v-model="data.measured_on"
                type="date"
                :max="today"
                required
            />
            <InputError :message="errors().measured_on" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div
                v-for="field in form.fields"
                :key="field.field"
                class="grid gap-2"
            >
                <Label :for="`${form.kind}-${field.field}`">
                    {{ field.label }}
                    <span class="font-normal text-muted-foreground"
                        >({{ field.unit }})</span
                    >
                </Label>
                <Input
                    :id="`${form.kind}-${field.field}`"
                    v-model="data[field.field]"
                    type="number"
                    inputmode="decimal"
                    :min="field.min"
                    :max="field.max"
                    :step="field.step"
                />
                <InputError :message="errors()[field.field]" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label :for="`${form.kind}-notes`">Notes (optional)</Label>
            <textarea
                :id="`${form.kind}-notes`"
                v-model="data.notes"
                rows="2"
                maxlength="1000"
                :class="textareaClass"
            />
            <InputError :message="errors().notes" />
        </div>

        <InputError :message="errors().measurements ?? errors().kind" />
        <Button type="submit" :disabled="data.processing">Save</Button>
    </form>
</template>
