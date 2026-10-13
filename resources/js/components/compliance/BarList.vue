<script setup lang="ts">
import { computed } from 'vue';

// One series of counts as horizontal bars, each labelled with its value.
const props = defineProps<{
    rows: { label: string; value: number | null }[];
    // Shown in place of a value that is withheld.
    withheld?: string;
    unit?: string;
}>();

const max = computed(() =>
    Math.max(1, ...props.rows.map((row) => row.value ?? 0)),
);

function width(value: number | null): string {
    return value ? `${Math.max(2, (value / max.value) * 100)}%` : '0';
}
</script>

<template>
    <dl class="space-y-2.5">
        <div
            v-for="row in rows"
            :key="row.label"
            class="grid grid-cols-[minmax(7rem,10rem)_1fr_auto] items-center gap-3 text-sm"
        >
            <dt class="truncate">{{ row.label }}</dt>
            <div
                class="h-2.5 rounded-sm bg-muted"
                :title="
                    row.value === null
                        ? (withheld ?? 'Not shown')
                        : `${row.label}: ${row.value} ${unit ?? ''}`
                "
            >
                <div
                    class="h-full rounded-sm bg-primary"
                    :style="{ width: width(row.value) }"
                />
            </div>
            <dd class="min-w-12 text-right tabular-nums">
                <template v-if="row.value !== null">{{ row.value }}</template>
                <span v-else class="text-xs text-muted-foreground">
                    {{ withheld ?? '—' }}
                </span>
            </dd>
        </div>
    </dl>
</template>
