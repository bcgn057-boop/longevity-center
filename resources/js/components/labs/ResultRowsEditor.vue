<script setup lang="ts">
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { emptyResultRow } from '@/lib/labs';
import type { BiomarkerGroup, BiomarkerOption, ResultRow } from '@/types';

const props = defineProps<{
    catalog: BiomarkerGroup[];
    errors: Record<string, string | undefined>;
    // Biomarkers already on the report, which must be corrected instead.
    exclude?: number[];
}>();

const rows = defineModel<ResultRow[]>({ required: true });

const MAX_ROWS = 100;

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';

const biomarkers = computed(
    () =>
        new Map<string, BiomarkerOption>(
            props.catalog.flatMap((group) =>
                group.biomarkers.map((marker) => [String(marker.id), marker]),
            ),
        ),
);

function unitsFor(row: ResultRow): string[] {
    return biomarkers.value.get(row.biomarker_id)?.units ?? [];
}

// A new biomarker starts on its usual unit.
function chooseBiomarker(row: ResultRow): void {
    const units = unitsFor(row);

    if (!units.includes(row.unit)) {
        row.unit = units[0] ?? '';
    }
}

function error(index: number, field: keyof ResultRow): string | undefined {
    return props.errors[`results.${index}.${field}`];
}

function addRow(): void {
    if (rows.value.length < MAX_ROWS) {
        rows.value.push(emptyResultRow());
    }
}

function removeRow(index: number): void {
    rows.value.splice(index, 1);
}
</script>

<template>
    <div class="space-y-3">
        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[56rem] text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-2 py-2 font-medium">Biomarker</th>
                        <th class="w-28 px-2 py-2 font-medium">Result</th>
                        <th class="w-32 px-2 py-2 font-medium">Unit</th>
                        <th class="w-48 px-2 py-2 font-medium">
                            Lab range (low – high)
                        </th>
                        <th class="px-2 py-2 font-medium">Comment</th>
                        <th class="w-10 px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, index) in rows"
                        :key="index"
                        class="border-b align-top last:border-0"
                    >
                        <td class="px-2 py-2">
                            <select
                                v-model="row.biomarker_id"
                                :class="selectClass"
                                :aria-label="`Biomarker ${index + 1}`"
                                @change="chooseBiomarker(row)"
                            >
                                <option value="" disabled>Select…</option>
                                <optgroup
                                    v-for="group in catalog"
                                    :key="group.category"
                                    :label="group.label"
                                >
                                    <option
                                        v-for="marker in group.biomarkers"
                                        :key="marker.id"
                                        :value="String(marker.id)"
                                        :disabled="exclude?.includes(marker.id)"
                                    >
                                        {{ marker.name }}
                                    </option>
                                </optgroup>
                            </select>
                            <InputError
                                :message="error(index, 'biomarker_id')"
                            />
                        </td>
                        <td class="px-2 py-2">
                            <Input
                                v-model="row.value"
                                maxlength="32"
                                placeholder="e.g. 5.4"
                                :aria-label="`Result ${index + 1}`"
                            />
                            <InputError :message="error(index, 'value')" />
                        </td>
                        <td class="px-2 py-2">
                            <select
                                v-model="row.unit"
                                :class="selectClass"
                                :disabled="!row.biomarker_id"
                                :aria-label="`Unit ${index + 1}`"
                            >
                                <option
                                    v-for="unit in unitsFor(row)"
                                    :key="unit"
                                    :value="unit"
                                >
                                    {{ unit }}
                                </option>
                            </select>
                            <InputError :message="error(index, 'unit')" />
                        </td>
                        <td class="px-2 py-2">
                            <div class="flex gap-1">
                                <Input
                                    v-model="row.reference_low"
                                    inputmode="decimal"
                                    placeholder="low"
                                    :aria-label="`Range low ${index + 1}`"
                                />
                                <Input
                                    v-model="row.reference_high"
                                    inputmode="decimal"
                                    placeholder="high"
                                    :aria-label="`Range high ${index + 1}`"
                                />
                            </div>
                            <InputError
                                :message="
                                    error(index, 'reference_low') ??
                                    error(index, 'reference_high')
                                "
                            />
                        </td>
                        <td class="px-2 py-2">
                            <Input
                                v-model="row.comment"
                                maxlength="500"
                                :aria-label="`Comment ${index + 1}`"
                            />
                            <InputError :message="error(index, 'comment')" />
                        </td>
                        <td class="px-2 py-2">
                            <Button
                                v-if="rows.length > 1"
                                type="button"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Remove row ${index + 1}`"
                                @click="removeRow(index)"
                            >
                                ×
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="flex items-center gap-3">
            <Button
                type="button"
                variant="outline"
                size="sm"
                :disabled="rows.length >= MAX_ROWS"
                @click="addRow"
            >
                Add row
            </Button>
            <span class="text-xs text-muted-foreground">
                Enter values exactly as the lab reported them, e.g. 5.4 or
                &lt;0.5. Units are never converted.
            </span>
        </div>
        <InputError :message="errors.results" />
    </div>
</template>
