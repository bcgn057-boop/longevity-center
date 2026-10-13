<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import { dashboardIcons } from '@/components/dashboard/icons';
import { toneFor } from '@/components/dashboard/tones';
import type { DashboardCard } from '@/types';

const props = defineProps<{ card: DashboardCard }>();

const icon = computed(() => dashboardIcons[props.card.key]);
const tone = computed(() => toneFor(props.card.key));
</script>

<template>
    <component
        :is="card.href ? Link : 'div'"
        :href="card.href ?? undefined"
        class="group relative flex flex-col gap-3 overflow-hidden rounded-xl border border-sidebar-border/70 bg-card bg-linear-to-br to-transparent to-60% p-4 pl-5 dark:border-sidebar-border"
        :class="[
            tone.wash,
            card.href &&
                'transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none motion-reduce:hover:translate-y-0',
        ]"
    >
        <span
            class="absolute inset-y-0 left-0 w-1"
            :class="tone.accent"
            aria-hidden="true"
        />
        <div class="flex items-start justify-between gap-3">
            <span class="text-sm font-medium text-muted-foreground">
                {{ card.label }}
            </span>
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                :class="tone.icon"
                aria-hidden="true"
            >
                <component :is="icon" v-if="icon" class="size-4.5" />
            </span>
        </div>
        <div class="text-3xl font-semibold tracking-tight tabular-nums">
            {{ card.value }}
        </div>
        <div
            class="flex items-center justify-between gap-2 text-xs text-muted-foreground"
        >
            <span>{{ card.detail }}</span>
            <ArrowUpRight
                v-if="card.href"
                class="size-3.5 shrink-0 opacity-0 transition-opacity group-hover:opacity-100 group-focus-visible:opacity-100"
                aria-hidden="true"
            />
        </div>
    </component>
</template>
