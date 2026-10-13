<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import PeriodFilter from '@/components/compliance/PeriodFilter.vue';
import { analytics, staffActivity } from '@/routes/compliance';
import { index as auditLogs } from '@/routes/compliance/audit-logs';
import type { ReportPeriod, StaffActivityRow } from '@/types';

const props = defineProps<{
    period: ReportPeriod;
    // Column key => heading, in display order.
    columns: Record<string, string>;
    staff: StaffActivityRow[];
    can: { viewAuditLogs: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Practice analytics', href: analytics() },
            { title: 'Staff activity', href: staffActivity() },
        ],
    },
});

function auditLink(row: StaffActivityRow) {
    return auditLogs({
        query: {
            user_id: row.id,
            from: props.period.from,
            to: props.period.to,
        },
    });
}

function formatDateTime(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '—';
}
</script>

<template>
    <Head title="Staff activity" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">Staff activity</h1>
            <p class="text-sm text-muted-foreground">
                What each staff account did, counted from the audit log. Which
                patients were involved is only in the audit log itself.
            </p>
        </div>

        <PeriodFilter :period="period" :url="staffActivity().url" />

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[60rem] text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Staff member</th>
                        <th
                            v-for="(label, key) in columns"
                            :key="key"
                            class="px-3 py-2 text-right font-medium"
                        >
                            {{ label }}
                        </th>
                        <th class="px-3 py-2 font-medium">Last active</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="staff.length === 0">
                        <td
                            :colspan="Object.keys(columns).length + 2"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            No staff accounts.
                        </td>
                    </tr>
                    <tr
                        v-for="row in staff"
                        :key="row.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-3 py-2">
                            <Link
                                v-if="can.viewAuditLogs"
                                :href="auditLink(row)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ row.name }}
                            </Link>
                            <span v-else class="font-medium">{{
                                row.name
                            }}</span>
                            <div class="text-xs text-muted-foreground">
                                {{ row.role }} · {{ row.email }}
                            </div>
                        </td>
                        <td
                            v-for="(_, key) in columns"
                            :key="key"
                            class="px-3 py-2 text-right tabular-nums"
                            :class="{
                                'text-muted-foreground': !row.counts[key],
                            }"
                        >
                            <span
                                v-if="
                                    key === 'access_denied' && row.counts[key]
                                "
                                class="inline-flex items-center gap-1 font-medium"
                                :title="`${row.counts[key]} refused requests: worth a look in the audit log`"
                            >
                                <TriangleAlert
                                    class="size-3.5 text-amber-600 dark:text-amber-400"
                                    aria-hidden="true"
                                />
                                {{ row.counts[key] }}
                                <span class="sr-only">refused requests</span>
                            </span>
                            <template v-else>{{ row.counts[key] }}</template>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ formatDateTime(row.last_active) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p class="text-xs text-muted-foreground">
            "Records accessed" counts every view, list and search of patient
            data. Click a name to see their entries in the audit log for this
            period.
        </p>
    </div>
</template>
