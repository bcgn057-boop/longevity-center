<script setup lang="ts">
import { computed, ref } from 'vue';
import type { TrendPoint } from '@/types';

const props = defineProps<{
    name: string;
    unit: string;
    points: TrendPoint[];
}>();

const WIDTH = 600;
const HEIGHT = 200;
const PAD = { top: 12, right: 20, bottom: 30, left: 56 };

const format = new Intl.NumberFormat(undefined, {
    maximumSignificantDigits: 4,
});

// Values reported as "<0.5" have no exact position, so they are listed
// below the chart instead of plotted.
const plotted = computed(() =>
    props.points.filter(
        (point): point is TrendPoint & { number: number } =>
            point.number !== null,
    ),
);
const unplotted = computed(() =>
    props.points.filter((point) => point.number === null),
);

// The shaded band is the most recent lab range: ranges can differ between labs.
const band = computed(() => {
    const latest = plotted.value.at(-1);

    return latest &&
        (latest.reference_low !== null || latest.reference_high !== null)
        ? { low: latest.reference_low, high: latest.reference_high }
        : null;
});

const domain = computed(() => {
    const values = plotted.value.map((point) => point.number);

    if (band.value?.low != null) values.push(band.value.low);
    if (band.value?.high != null) values.push(band.value.high);

    let min = Math.min(...values);
    let max = Math.max(...values);

    if (min === max) {
        min -= Math.abs(min) * 0.1 || 1;
        max += Math.abs(max) * 0.1 || 1;
    }

    const padding = (max - min) * 0.1;

    return { min: min - padding, max: max + padding };
});

const times = computed(() =>
    plotted.value.map((point) => Date.parse(point.date)),
);

function x(index: number): number {
    const first = times.value[0];
    const last = times.value.at(-1) ?? first;
    const span = last - first;
    const usable = WIDTH - PAD.left - PAD.right;

    return span === 0
        ? PAD.left + usable / 2
        : PAD.left + ((times.value[index] - first) / span) * usable;
}

function y(value: number): number {
    const { min, max } = domain.value;
    const usable = HEIGHT - PAD.top - PAD.bottom;

    return PAD.top + (1 - (value - min) / (max - min)) * usable;
}

const path = computed(() =>
    plotted.value
        .map(
            (point, index) =>
                `${index === 0 ? 'M' : 'L'}${x(index)},${y(point.number)}`,
        )
        .join(' '),
);

const ticks = computed(() => {
    const { min, max } = domain.value;

    return [0, 0.5, 1].map((share) => min + (max - min) * share);
});

const bandRect = computed(() => {
    if (!band.value) {
        return null;
    }

    const top = y(band.value.high ?? domain.value.max);
    const bottom = y(band.value.low ?? domain.value.min);

    return { y: top, height: Math.max(bottom - top, 0) };
});

const active = ref<number | null>(null);
const activePoint = computed(() =>
    active.value === null
        ? (plotted.value.at(-1) ?? null)
        : plotted.value[active.value],
);

function describe(point: TrendPoint): string {
    return `${point.date_label}: ${point.value} ${point.unit}${point.flag_label ? ` (${point.flag_label.toLowerCase()} of the lab's range)` : ''}`;
}
</script>

<template>
    <figure class="space-y-2">
        <svg
            v-if="plotted.length"
            :viewBox="`0 0 ${WIDTH} ${HEIGHT}`"
            class="h-auto w-full overflow-visible"
            role="img"
            :aria-label="`${name} over time, in ${unit}`"
        >
            <rect
                v-if="bandRect"
                :x="PAD.left"
                :y="bandRect.y"
                :width="WIDTH - PAD.left - PAD.right"
                :height="bandRect.height"
                class="fill-primary/10"
            />
            <g v-for="tick in ticks" :key="tick">
                <line
                    :x1="PAD.left"
                    :x2="WIDTH - PAD.right"
                    :y1="y(tick)"
                    :y2="y(tick)"
                    class="stroke-border"
                    stroke-dasharray="3 3"
                />
                <text
                    :x="PAD.left - 8"
                    :y="y(tick)"
                    text-anchor="end"
                    dominant-baseline="middle"
                    class="fill-muted-foreground text-[11px]"
                >
                    {{ format.format(tick) }}
                </text>
            </g>
            <text
                :x="x(0)"
                :y="HEIGHT - 8"
                :text-anchor="plotted.length > 1 ? 'start' : 'middle'"
                class="fill-muted-foreground text-[11px]"
            >
                {{ plotted[0].date_label }}
            </text>
            <text
                v-if="plotted.length > 1"
                :x="x(plotted.length - 1)"
                :y="HEIGHT - 8"
                text-anchor="end"
                class="fill-muted-foreground text-[11px]"
            >
                {{ plotted[plotted.length - 1].date_label }}
            </text>

            <path
                :d="path"
                fill="none"
                class="stroke-primary"
                stroke-width="2"
            />

            <circle
                v-for="(point, index) in plotted"
                :key="`${point.report_id}-${index}`"
                :cx="x(index)"
                :cy="y(point.number)"
                :r="active === index ? 7 : 5"
                tabindex="0"
                role="button"
                :aria-label="describe(point)"
                class="cursor-pointer stroke-background outline-none focus-visible:stroke-ring"
                :class="
                    point.flag && point.flag !== 'normal'
                        ? 'fill-amber-500'
                        : 'fill-primary'
                "
                stroke-width="2"
                @mouseenter="active = index"
                @focus="active = index"
                @mouseleave="active = null"
                @blur="active = null"
            >
                <title>{{ describe(point) }}</title>
            </circle>
        </svg>

        <figcaption
            class="space-y-1 text-xs text-muted-foreground"
            aria-live="polite"
        >
            <p v-if="activePoint" class="text-sm text-foreground">
                {{ describe(activePoint) }}
            </p>
            <p v-if="band">
                Shaded: the lab's range on the latest report ({{
                    band.low ?? '…'
                }}–{{ band.high ?? '…' }} {{ unit }}).
            </p>
            <p v-if="unplotted.length">
                Not plotted (below or above what the lab can measure):
                <span
                    v-for="(point, index) in unplotted"
                    :key="point.report_id"
                >
                    {{ point.date_label }} {{ point.value }} {{ point.unit
                    }}<span v-if="index < unplotted.length - 1">, </span>
                </span>
            </p>
        </figcaption>
    </figure>
</template>
