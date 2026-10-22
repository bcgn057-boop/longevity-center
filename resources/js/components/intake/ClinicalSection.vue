<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import IntakeQuestion from '@/components/intake/IntakeQuestion.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { clinical as saveClinical } from '@/routes/intake-forms';
import type { IntakeForm, IntakeSection } from '@/types';

// The clinician's functional assessment and scores. Editable by clinicians;
// read-only for the patient.
const props = defineProps<{
    form: IntakeForm;
    section: IntakeSection;
    editable: boolean;
}>();

const data = useForm({
    clinical: { ...props.form.clinical } as Record<string, any>,
});

function submit(): void {
    data.put(saveClinical(props.form.id).url, { preserveScroll: true });
}

const errors = () => data.errors as Record<string, string | undefined>;
</script>

<template>
    <section class="space-y-4 rounded-lg border p-4">
        <div>
            <h3 class="text-base font-medium">{{ section.title }}</h3>
            <p v-if="editable" class="text-sm text-muted-foreground">
                {{ section.description }} The five scores are entered by the
                clinician, following the clinic's scoring protocol.
            </p>
        </div>

        <div class="flex flex-wrap gap-3 text-sm">
            <div class="rounded-md border px-3 py-2">
                <div class="text-xs text-muted-foreground">BMI</div>
                <div class="font-semibold tabular-nums">
                    {{ form.derived.bmi ?? '—' }}
                </div>
            </div>
            <div class="rounded-md border px-3 py-2">
                <div class="text-xs text-muted-foreground">
                    Waist-to-height ratio
                </div>
                <div class="font-semibold tabular-nums">
                    {{ form.derived.waist_to_height ?? '—' }}
                </div>
            </div>
        </div>

        <form v-if="editable" class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="field in section.fields"
                    :key="field.key"
                    class="grid gap-1.5"
                    :class="{
                        'sm:col-span-2 lg:col-span-3':
                            field.type === 'textarea',
                    }"
                >
                    <Label :for="`clinical-${field.key}`">{{
                        field.label
                    }}</Label>
                    <IntakeQuestion
                        v-model="data.clinical[field.key]"
                        :field="field"
                        :id="`clinical-${field.key}`"
                    />
                    <InputError :message="errors()[`clinical.${field.key}`]" />
                </div>
            </div>
            <Button type="submit" :disabled="data.processing">
                Save assessment
            </Button>
        </form>

        <dl v-else class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
            <template v-for="field in section.fields" :key="field.key">
                <div v-if="form.clinical[field.key] !== undefined">
                    <dt class="text-muted-foreground">{{ field.label }}</dt>
                    <dd class="whitespace-pre-line">
                        {{ form.clinical[field.key] }}
                    </dd>
                </div>
            </template>
        </dl>
    </section>
</template>
