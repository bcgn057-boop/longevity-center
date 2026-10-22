<script setup lang="ts">
import { Plus, X } from '@lucide/vue';
import InputError from '@/components/InputError.vue';
import IntakeQuestion from '@/components/intake/IntakeQuestion.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { IntakeAnswers, IntakeSection } from '@/types';

// Edits one section of the questionnaire in place, whatever its shape.
const props = defineProps<{
    sectionKey: string;
    section: IntakeSection;
    errors: Record<string, string | undefined>;
}>();

const answer = defineModel<any>({ required: true });

function item(key: string): IntakeAnswers {
    answer.value[key] ??= {};

    return answer.value[key];
}

function addRow(): void {
    if ((answer.value as unknown[]).length < (props.section.max_rows ?? 40)) {
        (answer.value as IntakeAnswers[]).push({});
    }
}

function sectionErrors(): string[] {
    return Object.entries(props.errors)
        .filter(([key]) => key.startsWith(`${props.sectionKey}.`))
        .map(([, message]) => message as string);
}
</script>

<template>
    <fieldset class="space-y-4 rounded-lg border p-4">
        <legend class="px-1 text-base font-medium">{{ section.title }}</legend>
        <p v-if="section.description" class="text-sm text-muted-foreground">
            {{ section.description }}
        </p>

        <div
            v-if="section.shape === 'fields'"
            class="grid gap-4 sm:grid-cols-2"
        >
            <div
                v-for="field in section.fields"
                :key="field.key"
                class="grid gap-1.5"
                :class="{
                    'sm:col-span-2': field.type === 'textarea',
                    'flex items-center gap-2': field.type === 'boolean',
                }"
            >
                <Label :for="`${sectionKey}-${field.key}`">{{
                    field.label
                }}</Label>
                <IntakeQuestion
                    v-model="answer[field.key]"
                    :field="field"
                    :id="`${sectionKey}-${field.key}`"
                />
            </div>
        </div>

        <div v-else-if="section.shape === 'checklist'" class="overflow-x-auto">
            <table class="w-full min-w-[40rem] text-left text-sm">
                <thead class="border-b">
                    <tr>
                        <th class="py-2 pr-3 font-medium"></th>
                        <th
                            v-for="column in section.columns"
                            :key="column.key"
                            class="px-2 py-2 font-medium"
                        >
                            {{ column.label }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in section.items"
                        :key="row.key"
                        class="border-b align-middle last:border-0"
                    >
                        <th scope="row" class="py-2 pr-3 font-normal">
                            {{ row.label }}
                        </th>
                        <td
                            v-for="column in section.columns"
                            :key="column.key"
                            class="px-2 py-1.5"
                        >
                            <IntakeQuestion
                                v-model="item(row.key)[column.key]"
                                :field="column"
                                :id="`${sectionKey}-${row.key}-${column.key}`"
                                :label="`${row.label}: ${column.label}`"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
            <div
                v-for="field in section.extra ?? []"
                :key="field.key"
                class="mt-4 grid gap-1.5"
            >
                <Label :for="`${sectionKey}-${field.key}`">{{
                    field.label
                }}</Label>
                <IntakeQuestion
                    v-model="answer[field.key]"
                    :field="field"
                    :id="`${sectionKey}-${field.key}`"
                />
            </div>
        </div>

        <div v-else class="space-y-3">
            <div class="overflow-x-auto">
                <table class="w-full min-w-[44rem] text-left text-sm">
                    <thead class="border-b">
                        <tr>
                            <th
                                v-for="column in section.columns"
                                :key="column.key"
                                class="px-2 py-2 font-medium"
                            >
                                {{ column.label }}
                            </th>
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="answer.length === 0">
                            <td
                                :colspan="(section.columns?.length ?? 0) + 1"
                                class="px-2 py-3 text-muted-foreground"
                            >
                                Nothing added.
                            </td>
                        </tr>
                        <tr
                            v-for="(row, index) in answer"
                            :key="index"
                            class="border-b last:border-0"
                        >
                            <td
                                v-for="column in section.columns"
                                :key="column.key"
                                class="px-2 py-1.5"
                            >
                                <IntakeQuestion
                                    v-model="row[column.key]"
                                    :field="column"
                                    :id="`${sectionKey}-${index}-${column.key}`"
                                    :label="`Row ${Number(index) + 1}: ${column.label}`"
                                />
                            </td>
                            <td class="px-1">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    :aria-label="`Remove row ${Number(index) + 1}`"
                                    @click="answer.splice(index, 1)"
                                >
                                    <X class="size-4" />
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <Button type="button" variant="outline" size="sm" @click="addRow">
                <Plus class="size-4" /> Add a row
            </Button>
        </div>

        <InputError
            v-for="message in sectionErrors()"
            :key="message"
            :message="message"
        />
    </fieldset>
</template>
