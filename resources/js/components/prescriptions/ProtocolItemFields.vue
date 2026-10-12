<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { selectClass } from '@/lib/prescriptions';
import type { PrescriptionOptions, ProtocolItemRow } from '@/types';

const props = defineProps<{
    options: PrescriptionOptions;
    errors: Record<string, string | undefined>;
    // Prefix of this row's error keys, e.g. "items.0."
    errorPrefix?: string;
    idPrefix: string;
}>();

const row = defineModel<ProtocolItemRow>({ required: true });

function error(field: keyof ProtocolItemRow): string | undefined {
    return props.errors[`${props.errorPrefix ?? ''}${field}`];
}
</script>

<template>
    <div class="grid gap-3 sm:grid-cols-2">
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-type`">Type</Label>
            <select
                :id="`${idPrefix}-type`"
                v-model="row.type"
                :class="selectClass"
            >
                <option
                    v-for="option in options.types"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <InputError :message="error('type')" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-name`">Name</Label>
            <Input
                :id="`${idPrefix}-name`"
                v-model="row.name"
                maxlength="120"
                placeholder="e.g. Vitamin D3"
            />
            <InputError :message="error('name')" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-dosage`">Dose or amount</Label>
            <Input
                :id="`${idPrefix}-dosage`"
                v-model="row.dosage"
                maxlength="120"
                placeholder="e.g. 2000 IU"
            />
            <InputError :message="error('dosage')" />
        </div>
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-frequency`">How often</Label>
            <select
                :id="`${idPrefix}-frequency`"
                v-model="row.frequency"
                :class="selectClass"
            >
                <option
                    v-for="option in options.frequencies"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ option.label }}
                </option>
            </select>
            <InputError :message="error('frequency')" />
        </div>
        <div class="grid gap-2 sm:col-span-2">
            <Label :for="`${idPrefix}-instructions`">
                Instructions for the patient
            </Label>
            <Input
                :id="`${idPrefix}-instructions`"
                v-model="row.instructions"
                maxlength="500"
                placeholder="e.g. Take with breakfast"
            />
            <InputError :message="error('instructions')" />
        </div>
    </div>
</template>
