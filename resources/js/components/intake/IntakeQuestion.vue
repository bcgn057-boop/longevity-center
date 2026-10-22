<script setup lang="ts">
import { Input } from '@/components/ui/input';
import type { IntakeField } from '@/types';

// One question's input, by its type. Labels are given by the caller.
defineProps<{
    field: IntakeField;
    id: string;
    label?: string;
}>();

const value = defineModel<any>();

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';
const textareaClass =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';
</script>

<template>
    <input
        v-if="field.type === 'boolean'"
        :id="id"
        v-model="value"
        type="checkbox"
        :aria-label="label"
        class="size-4 accent-primary"
    />
    <select
        v-else-if="field.type === 'select'"
        :id="id"
        v-model="value"
        :aria-label="label"
        :class="selectClass"
    >
        <option :value="undefined">—</option>
        <option
            v-for="option in field.options"
            :key="option.value"
            :value="option.value"
        >
            {{ option.label }}
        </option>
    </select>
    <textarea
        v-else-if="field.type === 'textarea'"
        :id="id"
        v-model="value"
        rows="2"
        maxlength="2000"
        :aria-label="label"
        :class="textareaClass"
    />
    <Input
        v-else-if="field.type === 'number'"
        :id="id"
        v-model="value"
        type="number"
        inputmode="decimal"
        :min="field.min"
        :max="field.max"
        :step="field.step"
        :aria-label="label"
    />
    <Input
        v-else
        :id="id"
        v-model="value"
        maxlength="255"
        :aria-label="label"
    />
</template>
