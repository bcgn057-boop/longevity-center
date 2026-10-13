<script setup lang="ts">
import { computed } from 'vue';
import ColumnChart from '@/components/dashboard/ColumnChart.vue';
import { dashboardIcons } from '@/components/dashboard/icons';
import { toneFor } from '@/components/dashboard/tones';
import type { DashboardChart } from '@/types';

const props = defineProps<{
    chart: DashboardChart;
    compact?: boolean;
}>();

const tone = computed(() => toneFor(props.chart.key));
const icon = computed(() => dashboardIcons[props.chart.key]);
</script>

<template>
    <figure
        class="flex flex-col gap-3 rounded-xl border border-sidebar-border/70 bg-card p-4 dark:border-sidebar-border"
    >
        <figcaption class="flex items-start justify-between gap-3">
            <div>
                <div class="text-sm font-medium">{{ chart.title }}</div>
                <div class="text-xs text-muted-foreground">
                    <span class="font-medium text-foreground tabular-nums">{{
                        chart.total
                    }}</span>
                    {{ chart.unit }} in the last {{ chart.points.length }} days
                </div>
            </div>
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-lg"
                :class="tone.icon"
                aria-hidden="true"
            >
                <component :is="icon" v-if="icon" class="size-4" />
            </span>
        </figcaption>
        <ColumnChart :chart="chart" :height="compact ? 72 : 120" />
    </figure>
</template>
