<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { InertiaLinkProps } from '@inertiajs/vue3';
import FlagBadge from '@/components/labs/FlagBadge.vue';
import { Badge } from '@/components/ui/badge';
import type { LabHistory } from '@/types';

// Every report for one patient, from every lab: where they came from, a
// timeline, and each biomarker side by side across reports.
defineProps<{
    history: LabHistory;
    // Where a report's own page is, for the viewer.
    reportHref: (id: number) => InertiaLinkProps['href'];
    // Staff also see reports still under review.
    showStatus?: boolean;
}>();
</script>

<template>
    <div class="space-y-8">
        <section class="space-y-3">
            <h2 class="text-base font-medium">Sources</h2>
            <div class="flex flex-wrap gap-2">
                <div
                    v-for="source in history.sources"
                    :key="source.name"
                    class="rounded-lg border px-3 py-2 text-sm"
                >
                    <div class="font-medium">{{ source.name }}</div>
                    <div class="text-xs text-muted-foreground">
                        {{ source.reports }}
                        {{ source.reports === 1 ? 'report' : 'reports' }} ·
                        latest {{ source.latest }}
                    </div>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Side by side</h2>
            <p class="text-xs text-muted-foreground">
                Each value is shown as its lab reported it, in that lab's unit,
                and flagged against that lab's own range.
                <template v-if="history.comparison.hidden">
                    The {{ history.comparison.hidden }} oldest reports are in
                    the timeline below.
                </template>
            </p>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th
                                scope="col"
                                class="sticky left-0 min-w-48 bg-muted px-3 py-2 font-medium"
                            >
                                Biomarker
                            </th>
                            <th
                                v-for="column in history.comparison.columns"
                                :key="column.id"
                                scope="col"
                                class="min-w-32 px-3 py-2 align-bottom font-medium"
                            >
                                <Link
                                    :href="reportHref(column.id)"
                                    class="underline-offset-4 hover:underline"
                                >
                                    {{ column.date_label }}
                                </Link>
                                <div
                                    class="text-xs font-normal text-muted-foreground"
                                >
                                    {{ column.source }}
                                </div>
                                <Badge
                                    v-if="
                                        showStatus &&
                                        column.status !== 'released'
                                    "
                                    variant="outline"
                                    class="mt-1"
                                >
                                    Under review
                                </Badge>
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        v-for="group in history.comparison.groups"
                        :key="group.category"
                    >
                        <tr class="bg-muted/30">
                            <th
                                scope="rowgroup"
                                :colspan="history.comparison.columns.length + 1"
                                class="px-3 py-1.5 text-xs font-medium tracking-wide text-muted-foreground uppercase"
                            >
                                {{ group.label }}
                            </th>
                        </tr>
                        <tr
                            v-for="row in group.rows"
                            :key="row.biomarker"
                            class="border-b last:border-0"
                        >
                            <th
                                scope="row"
                                class="sticky left-0 bg-background px-3 py-2 font-normal"
                            >
                                {{ row.biomarker }}
                            </th>
                            <td
                                v-for="(cell, index) in row.cells"
                                :key="index"
                                class="px-3 py-2 whitespace-nowrap"
                            >
                                <template v-if="cell">
                                    <span class="font-medium tabular-nums">{{
                                        cell.value
                                    }}</span>
                                    <span
                                        class="ml-1 text-xs text-muted-foreground"
                                        >{{ cell.unit }}</span
                                    >
                                    <FlagBadge
                                        v-if="
                                            cell.flag && cell.flag !== 'normal'
                                        "
                                        :flag="cell.flag"
                                        :label="cell.flag_label"
                                        class="ml-1"
                                    />
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Timeline</h2>
            <ol class="relative space-y-4 border-l pl-6">
                <li
                    v-for="entry in history.timeline"
                    :key="entry.id"
                    class="relative"
                >
                    <span
                        class="absolute top-1.5 -left-[1.6rem] size-2.5 rounded-full border-2 border-background bg-primary"
                        aria-hidden="true"
                    />
                    <div class="flex flex-wrap items-baseline gap-x-2 text-sm">
                        <Link
                            :href="reportHref(entry.id)"
                            class="font-medium underline-offset-4 hover:underline"
                        >
                            {{ entry.date_label }}
                        </Link>
                        <span>{{ entry.source }}</span>
                        <Badge
                            v-if="showStatus && entry.status !== 'released'"
                            variant="outline"
                        >
                            {{ entry.status_label }}
                        </Badge>
                    </div>
                    <div class="text-xs text-muted-foreground">
                        <span v-if="entry.tests.length">
                            {{ entry.tests.join(', ') }} ·
                        </span>
                        {{ entry.results }}
                        {{ entry.results === 1 ? 'result' : 'results' }}
                        <span v-if="entry.flagged">
                            · {{ entry.flagged }} outside the lab's range
                        </span>
                    </div>
                </li>
            </ol>
        </section>
    </div>
</template>
