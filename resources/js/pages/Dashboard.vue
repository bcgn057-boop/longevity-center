<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ChartCard from '@/components/dashboard/ChartCard.vue';
import DashboardCard from '@/components/dashboard/DashboardCard.vue';
import { dashboardIcons } from '@/components/dashboard/icons';
import { toneFor } from '@/components/dashboard/tones';
import { dashboard } from '@/routes';
import type { DashboardAction, DashboardSection } from '@/types';

const props = defineProps<{
    greeting: string;
    role: string | null;
    // Y-m-d in the clinic's timezone.
    today: string;
    sections: DashboardSection[];
    actions: DashboardAction[];
    notice: string | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const salutation = computed(() => {
    const hour = new Date().getHours();

    return hour < 12
        ? 'Good morning'
        : hour < 18
          ? 'Good afternoon'
          : 'Good evening';
});

const todayLabel = computed(() => {
    const [year, month, day] = props.today.split('-').map(Number);

    return new Date(Date.UTC(year, month - 1, day)).toLocaleDateString(
        undefined,
        { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' },
    );
});
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-8 overflow-x-auto p-4">
        <div
            class="rounded-2xl border border-sidebar-border/70 bg-linear-to-r from-emerald-600/10 via-sky-600/5 to-violet-600/10 px-5 py-6 dark:border-sidebar-border dark:from-emerald-600/15 dark:to-violet-600/15"
        >
            <h1 class="text-2xl font-semibold tracking-tight">
                {{ salutation }}, {{ greeting }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ todayLabel
                }}<span v-if="role">
                    ·
                    <span class="font-medium text-foreground">{{ role }}</span>
                </span>
            </p>
        </div>

        <p
            v-if="notice"
            class="rounded-xl border px-4 py-3 text-sm text-muted-foreground"
        >
            {{ notice }}
        </p>

        <section
            v-for="section in sections"
            :key="section.title"
            class="space-y-4"
        >
            <h2
                class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
            >
                {{ section.title }}
            </h2>
            <p
                v-if="section.empty"
                class="rounded-xl border px-4 py-3 text-sm text-muted-foreground"
            >
                {{ section.empty }}
            </p>
            <template v-else>
                <div
                    class="grid auto-rows-fr gap-4 sm:grid-cols-2 xl:grid-cols-4"
                >
                    <DashboardCard
                        v-for="card in section.cards"
                        :key="card.key"
                        :card="card"
                    />
                </div>
                <div
                    v-if="section.charts?.length"
                    class="grid gap-4"
                    :class="
                        section.charts.length >= 3
                            ? 'md:grid-cols-3'
                            : 'md:grid-cols-2'
                    "
                >
                    <ChartCard
                        v-for="chart in section.charts"
                        :key="chart.key"
                        :chart="chart"
                        :compact="section.charts.length >= 3"
                    />
                </div>
                <p
                    v-if="(section.charts?.length ?? 0) >= 3"
                    class="text-xs text-muted-foreground"
                >
                    Each chart has its own scale, so small counts stay visible.
                    Compare the totals, not the bar heights.
                </p>
            </template>
        </section>

        <section v-if="actions.length" class="space-y-4">
            <h2
                class="text-sm font-semibold tracking-wide text-muted-foreground uppercase"
            >
                Quick actions
            </h2>
            <div
                class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
            >
                <component
                    :is="action.download ? 'a' : Link"
                    v-for="action in actions"
                    :key="action.key + action.href"
                    :href="action.href"
                    :download="action.download || undefined"
                    class="group flex items-center gap-3 rounded-xl border border-sidebar-border/70 bg-card px-4 py-3 transition duration-200 hover:-translate-y-0.5 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none motion-reduce:hover:translate-y-0 dark:border-sidebar-border"
                >
                    <span
                        class="flex size-9 shrink-0 items-center justify-center rounded-lg"
                        :class="toneFor(action.key).icon"
                        aria-hidden="true"
                    >
                        <component
                            :is="dashboardIcons[action.key]"
                            v-if="dashboardIcons[action.key]"
                            class="size-4"
                        />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-sm font-medium">
                            {{ action.label }}
                        </span>
                        <span
                            class="block truncate text-xs text-muted-foreground"
                        >
                            {{ action.description }}
                        </span>
                    </span>
                </component>
            </div>
        </section>

        <p v-if="sections.length" class="text-xs text-muted-foreground">
            Figures are counts only. Open a card to see the details, which are
            recorded in the audit log.
        </p>
    </div>
</template>
