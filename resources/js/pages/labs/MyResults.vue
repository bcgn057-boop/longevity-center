<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ref } from 'vue';
import FlagBadge from '@/components/labs/FlagBadge.vue';
import LabHistory from '@/components/labs/LabHistory.vue';
import TrendChart from '@/components/labs/TrendChart.vue';
import { Button } from '@/components/ui/button';
import { index, show } from '@/routes/my-results';
import type {
    LabHistory as LabHistoryData,
    PatientReportSummary,
    TrendGroup,
} from '@/types';

defineProps<{
    linked: boolean;
    groups: TrendGroup[];
    reports: PatientReportSummary[];
    // Null when the account is not linked to a patient record.
    history: LabHistoryData | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My results', href: index() }],
    },
});

const open = ref<number | null>(null);
</script>

<template>
    <Head title="My results" />

    <div class="flex h-full max-w-5xl flex-1 flex-col gap-8 p-4">
        <div>
            <h1 class="text-xl font-semibold">My results</h1>
            <p class="text-sm text-muted-foreground">
                Results appear here once your clinician has reviewed them. Flags
                compare each value with the range printed by the lab and are not
                a diagnosis. Please talk to your clinician about what your
                results mean for you.
            </p>
        </div>

        <p v-if="!linked" class="text-sm text-muted-foreground">
            Your account is not linked to a patient record yet. Please contact
            the clinic.
        </p>

        <p
            v-else-if="groups.length === 0"
            class="text-sm text-muted-foreground"
        >
            You have no released results yet.
        </p>

        <section
            v-for="group in groups"
            :key="group.category"
            class="space-y-3"
        >
            <h2 class="text-base font-medium">{{ group.label }}</h2>

            <div class="divide-y rounded-lg border">
                <div v-for="marker in group.markers" :key="marker.id">
                    <button
                        type="button"
                        class="flex w-full flex-wrap items-center justify-between gap-3 px-4 py-3 text-left hover:bg-muted/40"
                        :aria-expanded="open === marker.id"
                        @click="open = open === marker.id ? null : marker.id"
                    >
                        <span class="font-medium">{{ marker.name }}</span>
                        <span class="flex flex-wrap items-center gap-3 text-sm">
                            <span>
                                <span class="font-medium">{{
                                    marker.latest.value
                                }}</span>
                                {{ marker.latest.unit }}
                            </span>
                            <FlagBadge
                                :flag="marker.latest.flag"
                                :label="marker.latest.flag_label"
                            />
                            <span class="text-xs text-muted-foreground">
                                {{ marker.latest.date_label }}
                            </span>
                            <span
                                v-if="marker.latest.updated"
                                class="text-xs text-muted-foreground"
                            >
                                (updated)
                            </span>
                        </span>
                    </button>

                    <div v-if="open === marker.id" class="space-y-6 px-4 pb-4">
                        <div
                            v-for="series in marker.series"
                            :key="series.unit"
                            class="space-y-3"
                        >
                            <p
                                v-if="marker.series.length > 1"
                                class="text-xs text-muted-foreground"
                            >
                                Results reported in {{ series.unit }}. Results
                                in other units are shown separately and never
                                converted.
                            </p>
                            <TrendChart
                                :name="marker.name"
                                :unit="series.unit"
                                :points="series.points"
                            />
                            <table class="w-full text-left text-sm">
                                <caption class="sr-only">
                                    {{
                                        marker.name
                                    }}
                                    results in
                                    {{
                                        series.unit
                                    }}
                                </caption>
                                <thead class="text-xs text-muted-foreground">
                                    <tr>
                                        <th class="py-1 font-normal">Date</th>
                                        <th class="py-1 font-normal">Result</th>
                                        <th class="py-1 font-normal">
                                            Lab range
                                        </th>
                                        <th class="py-1 font-normal">Flag</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="point in [
                                            ...series.points,
                                        ].reverse()"
                                        :key="point.report_id"
                                        class="border-t"
                                    >
                                        <td class="py-1">
                                            <Link
                                                :href="show(point.report_id)"
                                                class="underline-offset-4 hover:underline"
                                            >
                                                {{ point.date_label }}
                                            </Link>
                                        </td>
                                        <td class="py-1">
                                            {{ point.value }} {{ point.unit }}
                                            <span
                                                v-if="point.updated"
                                                class="text-xs text-muted-foreground"
                                            >
                                                (updated)
                                            </span>
                                        </td>
                                        <td class="py-1">
                                            <template
                                                v-if="
                                                    point.reference_low !==
                                                        null ||
                                                    point.reference_high !==
                                                        null
                                                "
                                            >
                                                {{
                                                    point.reference_low ?? '…'
                                                }}–{{
                                                    point.reference_high ?? '…'
                                                }}
                                            </template>
                                            <template v-else>—</template>
                                        </td>
                                        <td class="py-1">
                                            <FlagBadge
                                                :flag="point.flag"
                                                :label="point.flag_label"
                                            />
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <section
            v-if="history && history.timeline.length"
            class="space-y-4 border-t pt-6"
        >
            <div>
                <h2 class="text-lg font-semibold">All your labs together</h2>
                <p class="text-sm text-muted-foreground">
                    Your released reports from every lab the clinic works with,
                    in one place.
                </p>
            </div>
            <LabHistory :history="history" :report-href="(id) => show(id)" />
        </section>

        <section v-if="reports.length" class="space-y-3">
            <h2 class="text-base font-medium">Reports</h2>
            <ul class="divide-y rounded-lg border text-sm">
                <li
                    v-for="report in reports"
                    :key="report.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-4 py-2"
                >
                    <span>
                        Collected {{ report.collected }}
                        <span class="text-muted-foreground">
                            · {{ report.source }}
                        </span>
                    </span>
                    <Button variant="outline" size="sm" as-child>
                        <Link :href="show(report.id)">View report</Link>
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
