<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, ref, useId } from 'vue';
import { toneFor } from '@/components/dashboard/tones';
import type { DashboardChart } from '@/types';

// One series of daily counts as columns, with a tooltip on hover or focus
// and a table for screen readers.
const props = defineProps<{
    chart: DashboardChart;
    height?: number;
}>();

const container = ref<HTMLElement | null>(null);
const { width } = useElementSize(container);

const id = useId();
const tone = computed(() => toneFor(props.chart.key));
const plotHeight = computed(() => props.height ?? 120);

const TOP = 18; // room for direct labels
const BOTTOM = 20; // room for day labels
const svgHeight = computed(() => plotHeight.value + TOP + BOTTOM);
const baseline = computed(() => TOP + plotHeight.value);

const max = computed(() =>
    Math.max(1, ...props.chart.points.map((point) => point.value)),
);
const band = computed(
    () => width.value / Math.max(1, props.chart.points.length),
);
const barWidth = computed(() => Math.min(36, Math.max(6, band.value * 0.6)));

const lastIndex = computed(() => props.chart.points.length - 1);
const maxIndex = computed(() =>
    props.chart.points.reduce(
        (best, point, index, points) =>
            point.value > points[best].value ? index : best,
        0,
    ),
);

function day(date: string, options: Intl.DateTimeFormatOptions): string {
    const [year, month, dayOfMonth] = date.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, dayOfMonth)).toLocaleDateString(
        undefined,
        { ...options, timeZone: 'UTC' },
    );
}

const bars = computed(() =>
    props.chart.points.map((point, index) => {
        const h = (point.value / max.value) * plotHeight.value;
        const x = band.value * index + (band.value - barWidth.value) / 2;
        const y = baseline.value - h;
        const r = Math.min(4, h, barWidth.value / 2);

        return {
            ...point,
            index,
            center: band.value * index + band.value / 2,
            y,
            // Rounded at the data end, square on the baseline.
            path:
                h <= 0
                    ? ''
                    : `M${x},${baseline.value}V${y + r}Q${x},${y} ${x + r},${y}H${x + barWidth.value - r}Q${x + barWidth.value},${y} ${x + barWidth.value},${y + r}V${baseline.value}Z`,
            tick:
                index === lastIndex.value
                    ? 'Today'
                    : day(point.date, { weekday: 'short' }),
            long: `${day(point.date, { weekday: 'long', day: 'numeric', month: 'long' })}: ${point.value} ${props.chart.unit}`,
            labelled:
                point.value > 0 &&
                (index === lastIndex.value || index === maxIndex.value),
        };
    }),
);

const active = ref<number | null>(null);
const activeBar = computed(() =>
    active.value === null ? null : bars.value[active.value],
);
</script>

<template>
    <div ref="container" class="relative w-full">
        <svg
            v-if="width > 0"
            :width="width"
            :height="svgHeight"
            :aria-labelledby="`${id}-title`"
            role="group"
            class="block overflow-visible"
            @mouseleave="active = null"
        >
            <title :id="`${id}-title`">
                {{ chart.title }}, last {{ chart.points.length }} days
            </title>
            <line
                x1="0"
                :x2="width"
                :y1="baseline"
                :y2="baseline"
                class="stroke-border"
                stroke-width="1"
            />
            <g v-for="bar in bars" :key="bar.date">
                <path
                    v-if="bar.path"
                    :d="bar.path"
                    :class="tone.fill"
                    class="transition-opacity duration-150"
                    :opacity="
                        active === null || active === bar.index ? 1 : 0.45
                    "
                />
                <text
                    v-if="bar.labelled"
                    :x="bar.center"
                    :y="bar.y - 5"
                    text-anchor="middle"
                    class="fill-foreground text-[11px] font-medium tabular-nums"
                >
                    {{ bar.value }}
                </text>
                <text
                    :x="bar.center"
                    :y="svgHeight - 4"
                    text-anchor="middle"
                    class="text-[11px]"
                    :class="
                        bar.index === lastIndex
                            ? 'fill-foreground font-medium'
                            : 'fill-muted-foreground'
                    "
                >
                    {{ bar.tick }}
                </text>
                <!-- A hit area the full height of the band, larger than the mark. -->
                <rect
                    :x="band * bar.index"
                    y="0"
                    :width="band"
                    :height="baseline"
                    fill="transparent"
                    tabindex="0"
                    :aria-label="bar.long"
                    class="cursor-default outline-none focus-visible:stroke-ring focus-visible:stroke-2"
                    @mouseenter="active = bar.index"
                    @focus="active = bar.index"
                    @blur="active = null"
                />
            </g>
        </svg>

        <div
            v-if="activeBar"
            class="pointer-events-none absolute z-10 -translate-x-1/2 -translate-y-full rounded-md border bg-popover px-2 py-1 text-xs whitespace-nowrap text-popover-foreground shadow-md"
            :style="{
                left: `${activeBar.center}px`,
                top: `${activeBar.y - 8}px`,
            }"
            aria-hidden="true"
        >
            {{ activeBar.long }}
        </div>

        <!-- Hidden on a wrapper: browsers draw a caption outside its table's box,
             so sr-only on the table itself leaves the caption visible. -->
        <div class="sr-only">
            <table>
                <caption>
                    {{
                        chart.title
                    }}, by day
                </caption>
                <tbody>
                    <tr v-for="point in chart.points" :key="point.date">
                        <th scope="row">
                            {{
                                day(point.date, {
                                    weekday: 'long',
                                    day: 'numeric',
                                    month: 'long',
                                })
                            }}
                        </th>
                        <td>{{ point.value }} {{ chart.unit }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
