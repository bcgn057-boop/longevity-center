<script setup lang="ts">
import type { IntakeAnswers, IntakeField, IntakeSection } from '@/types';

// A read-only view of the patient's answers, showing only what was given.
const props = defineProps<{
    schema: Record<string, IntakeSection>;
    answers: Record<string, IntakeAnswers>;
}>();

function display(field: IntakeField, value: unknown): string {
    if (value === undefined || value === null || value === '') {
        return '—';
    }

    if (field.type === 'boolean') {
        return value ? 'Yes' : 'No';
    }

    if (field.type === 'select') {
        return (
            field.options?.find((option) => option.value === value)?.label ??
            String(value)
        );
    }

    return String(value);
}

function given(key: string): boolean {
    const answer = props.answers[key];

    return Array.isArray(answer)
        ? answer.length > 0
        : Object.keys(answer ?? {}).length > 0;
}
</script>

<template>
    <div class="space-y-4">
        <section
            v-for="(section, key) in schema"
            :key="key"
            class="rounded-lg border p-4 text-sm"
        >
            <h3 class="mb-2 font-medium">{{ section.title }}</h3>
            <p v-if="!given(String(key))" class="text-muted-foreground">
                Not answered.
            </p>

            <dl
                v-else-if="section.shape === 'fields'"
                class="grid gap-x-6 gap-y-2 sm:grid-cols-2"
            >
                <template v-for="field in section.fields" :key="field.key">
                    <div v-if="answers[key]?.[field.key] !== undefined">
                        <dt class="text-muted-foreground">{{ field.label }}</dt>
                        <dd class="whitespace-pre-line">
                            {{ display(field, answers[key][field.key]) }}
                        </dd>
                    </div>
                </template>
            </dl>

            <ul v-else-if="section.shape === 'checklist'" class="space-y-1">
                <template v-for="item in section.items" :key="item.key">
                    <li v-if="answers[key]?.[item.key]?.present">
                        <span class="font-medium">{{ item.label }}</span>
                        <template
                            v-for="column in (section.columns ?? []).filter(
                                (c) => c.key !== 'present',
                            )"
                            :key="column.key"
                        >
                            <span
                                v-if="
                                    answers[key][item.key][column.key] !==
                                    undefined
                                "
                                class="text-muted-foreground"
                            >
                                · {{ column.label }}:
                                {{ answers[key][item.key][column.key] }}
                            </span>
                        </template>
                    </li>
                </template>
                <li
                    v-for="field in section.extra ?? []"
                    v-show="answers[key]?.[field.key]"
                    :key="field.key"
                >
                    <span class="text-muted-foreground"
                        >{{ field.label }}:</span
                    >
                    {{ answers[key]?.[field.key] }}
                </li>
            </ul>

            <table v-else class="w-full text-left">
                <thead>
                    <tr class="text-muted-foreground">
                        <th
                            v-for="column in section.columns"
                            :key="column.key"
                            class="py-1 pr-3 font-normal"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in answers[key]" :key="index">
                        <td
                            v-for="column in section.columns"
                            :key="column.key"
                            class="py-1 pr-3"
                        >
                            {{ display(column, row[column.key]) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
