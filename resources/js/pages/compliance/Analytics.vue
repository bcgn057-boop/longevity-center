<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import BarList from '@/components/compliance/BarList.vue';
import PeriodFilter from '@/components/compliance/PeriodFilter.vue';
import StatTile from '@/components/compliance/StatTile.vue';
import { analytics, staffActivity } from '@/routes/compliance';
import type { PracticeMetrics, ReportPeriod } from '@/types';

const props = defineProps<{
    period: ReportPeriod;
    metrics: PracticeMetrics;
    minGroupSize: number;
    can: { viewStaffActivity: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Practice analytics', href: analytics() }],
    },
});

const adherence = computed(() => props.metrics.adherence);
</script>

<template>
    <Head title="Practice analytics" />

    <div class="flex h-full max-w-6xl flex-1 flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Practice analytics</h1>
                <p class="text-sm text-muted-foreground">
                    Clinic-wide counts and rates. No figure here identifies a
                    patient.
                </p>
            </div>
            <Link
                v-if="can.viewStaffActivity"
                :href="
                    staffActivity({
                        query: { from: period.from, to: period.to },
                    })
                "
                class="text-sm font-medium underline underline-offset-4"
            >
                Staff activity for this period
            </Link>
        </div>

        <PeriodFilter :period="period" :url="analytics().url" />

        <section class="space-y-3">
            <h2 class="text-base font-medium">Patients</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Active patients"
                    :value="metrics.patients.active"
                />
                <StatTile
                    label="With a portal account"
                    :value="metrics.patients.with_portal"
                />
                <StatTile
                    label="New in this period"
                    :value="metrics.patients.new"
                />
                <StatTile label="Archived" :value="metrics.patients.archived" />
            </div>
        </section>

        <section class="space-y-3">
            <h2 class="text-base font-medium">Protocols</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatTile
                    label="Prescriptions in effect today"
                    :value="metrics.prescriptions.in_effect"
                />
                <StatTile
                    label="Patients on a protocol today"
                    :value="metrics.prescriptions.patients_on_protocol"
                />
                <StatTile
                    label="Prescriptions issued"
                    :value="metrics.prescriptions.issued"
                    detail="In this period"
                />
                <StatTile
                    label="Adherence"
                    :value="
                        adherence.rate === null ? '—' : `${adherence.rate}%`
                    "
                    :detail="
                        adherence.scheduled
                            ? `${adherence.taken} of ${adherence.scheduled} scheduled days ticked`
                            : 'Nothing scheduled in this period'
                    "
                />
            </div>
            <div v-if="adherence.patients" class="rounded-lg border p-4">
                <h3 class="text-sm font-medium">
                    Patients by their own adherence rate
                </h3>
                <p class="mb-4 text-xs text-muted-foreground">
                    {{ adherence.patients }} patients with daily items in this
                    period. Groups of fewer than {{ minGroupSize }} patients are
                    not shown, so they cannot be matched to individuals.
                </p>
                <BarList
                    :rows="
                        adherence.groups.map((group) => ({
                            label: group.label,
                            value: group.patients,
                        }))
                    "
                    :withheld="`Fewer than ${minGroupSize}`"
                    unit="patients"
                />
            </div>
            <p class="text-xs text-muted-foreground">
                Adherence counts items patients tick daily, as they recorded
                them, up to today.
            </p>
        </section>

        <div class="grid gap-8 lg:grid-cols-2">
            <section class="space-y-3">
                <h2 class="text-base font-medium">
                    Appointments
                    <span class="font-normal text-muted-foreground">
                        · {{ metrics.appointments.total }} in this period
                    </span>
                </h2>
                <div class="rounded-lg border p-4">
                    <BarList
                        :rows="
                            metrics.appointments.by_status.map((row) => ({
                                label: row.label,
                                value: row.count,
                            }))
                        "
                        unit="appointments"
                    />
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-base font-medium">Lab results</h2>
                <div
                    class="grid gap-3 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-3"
                >
                    <StatTile
                        label="Waiting for review"
                        :value="metrics.labs.pending_review"
                        detail="Now"
                    />
                    <StatTile
                        label="Released"
                        :value="metrics.labs.released"
                        detail="In this period"
                    />
                    <StatTile
                        label="Average time to release"
                        :value="
                            metrics.labs.average_review_hours === null
                                ? '—'
                                : `${metrics.labs.average_review_hours} h`
                        "
                        detail="From entry to release"
                    />
                </div>
            </section>
        </div>
    </div>
</template>
