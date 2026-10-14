<script setup lang="ts">
import { Paperclip } from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { create, index, show } from '@/routes/labs';
import type { LabReportSummary, Paginated, SelectOption } from '@/types';

const props = defineProps<{
    reports: Paginated<LabReportSummary>;
    filters: { status: string };
    statuses: SelectOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lab results', href: index() }],
    },
});

const status = ref(props.filters.status);

function apply(): void {
    router.get(
        index().url,
        { status: status.value },
        { preserveState: true, preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Lab results" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Lab results</h1>
                <p class="text-sm text-muted-foreground">
                    {{ reports.total }} reports. Patients only see a report once
                    a doctor or practitioner releases it.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()">Enter lab report</Link>
            </Button>
        </div>

        <form
            :action="index().url"
            method="get"
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="apply"
        >
            <div class="grid gap-1.5">
                <Label for="status">Status</Label>
                <select
                    id="status"
                    v-model="status"
                    name="status"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30"
                >
                    <option value="all">All reports</option>
                    <option
                        v-for="option in statuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </div>
            <Button type="submit">Show</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Collected</th>
                        <th class="px-3 py-2 font-medium">Patient</th>
                        <th class="px-3 py-2 font-medium">Lab</th>
                        <th class="px-3 py-2 font-medium">Results</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">Entered by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="reports.data.length === 0">
                        <td
                            colspan="6"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            No reports here.
                        </td>
                    </tr>
                    <tr
                        v-for="report in reports.data"
                        :key="report.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-3 py-2">
                            <Link
                                :href="show(report.id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ report.collected }}
                            </Link>
                        </td>
                        <td class="px-3 py-2">
                            {{ report.patient.full_name }}
                            <span
                                class="ml-1 font-mono text-xs text-muted-foreground"
                            >
                                {{ report.patient.medical_record_number }}
                            </span>
                        </td>
                        <td class="px-3 py-2">
                            {{ report.lab_name ?? '—' }}
                            <Paperclip
                                v-if="report.has_attachment"
                                class="ml-1 inline size-3.5 text-muted-foreground"
                                aria-label="PDF attached"
                            />
                        </td>
                        <td class="px-3 py-2">{{ report.results_count }}</td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    report.status === 'released'
                                        ? 'secondary'
                                        : 'default'
                                "
                            >
                                {{ report.status_label }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2">
                            {{ report.entered_by ?? '—' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="reports.last_page > 1" class="flex justify-end gap-2">
            <Button
                v-if="reports.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="reports.prev_page_url" preserve-scroll
                    >Previous</Link
                >
            </Button>
            <Button
                v-if="reports.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="reports.next_page_url" preserve-scroll>Next</Link>
            </Button>
        </div>
    </div>
</template>
