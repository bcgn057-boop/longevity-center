<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { AssessmentEntry, LatestMeasurement } from '@/types';

defineProps<{
    latest: LatestMeasurement[];
    history: AssessmentEntry[];
}>();
</script>

<template>
    <div class="space-y-6">
        <section class="space-y-3">
            <h2 class="text-base font-medium">Latest</h2>
            <p v-if="latest.length === 0" class="text-sm text-muted-foreground">
                Nothing recorded yet.
            </p>
            <div v-else class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    v-for="item in latest"
                    :key="item.field"
                    class="rounded-lg border p-3"
                >
                    <div class="text-xs text-muted-foreground">
                        {{ item.label }}
                    </div>
                    <div class="text-xl font-semibold tabular-nums">
                        {{ item.value }}
                        <span
                            class="text-sm font-normal text-muted-foreground"
                            >{{ item.unit }}</span
                        >
                    </div>
                    <div class="text-xs text-muted-foreground">
                        {{ item.date }}
                    </div>
                </div>
            </div>
        </section>

        <section v-if="history.length" class="space-y-3">
            <h2 class="text-base font-medium">History</h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="entry in history"
                    :key="entry.id"
                    class="space-y-1 px-4 py-3"
                >
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ entry.measured_on }}</span>
                        <Badge variant="outline">{{ entry.kind_label }}</Badge>
                        <span class="text-xs text-muted-foreground">
                            {{
                                entry.via === 'portal'
                                    ? 'Entered by the patient'
                                    : 'Recorded at the clinic'
                            }}<template v-if="entry.recorded_by">
                                · {{ entry.recorded_by }}</template
                            >
                        </span>
                    </div>
                    <div class="flex flex-wrap gap-x-4 gap-y-1">
                        <span v-for="value in entry.values" :key="value.label">
                            <span class="text-muted-foreground"
                                >{{ value.label }}:</span
                            >
                            {{ value.value }} {{ value.unit }}
                        </span>
                        <span v-if="entry.bmi">
                            <span class="text-muted-foreground">BMI:</span>
                            {{ entry.bmi }}
                        </span>
                    </div>
                    <div v-if="entry.notes" class="text-muted-foreground">
                        {{ entry.notes }}
                    </div>
                </li>
            </ul>
            <p class="text-xs text-muted-foreground">
                Entries are never changed. To correct one, record a new entry.
            </p>
        </section>
    </div>
</template>
