<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Download, FileText } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import CorrectResultDialog from '@/components/labs/CorrectResultDialog.vue';
import FlagBadge from '@/components/labs/FlagBadge.vue';
import ResultRowsEditor from '@/components/labs/ResultRowsEditor.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { emptyResultRow } from '@/lib/labs';
import { index, release } from '@/routes/labs';
import { store as storeResults } from '@/routes/labs/results';
import { show as showPatient } from '@/routes/patients';
import type { BiomarkerGroup, LabReport, ResultRow } from '@/types';

const props = defineProps<{
    report: LabReport;
    catalog: BiomarkerGroup[];
    can: {
        addResults: boolean;
        correct: boolean;
        release: boolean;
        viewPatient: boolean;
    };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lab results', href: index() }],
    },
});

const openHistory = ref<number | null>(null);

const addForm = useForm<{ results: ResultRow[] }>({
    results: [emptyResultRow()],
});
const releaseForm = useForm({});

const existing = props.report.groups.flatMap((group) =>
    group.results.map((result) => result.biomarker.id),
);

function addResults(): void {
    addForm.post(storeResults(props.report.id).url, {
        preserveScroll: true,
        onSuccess: () => addForm.reset(),
    });
}

function formatSize(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function submitRelease(): void {
    releaseForm.post(release(props.report.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Lab report" />

    <div class="flex h-full max-w-6xl flex-1 flex-col gap-8 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="space-y-1">
                <h1 class="text-xl font-semibold">
                    Lab report: {{ report.collected }}
                </h1>
                <div class="text-sm">
                    <Link
                        v-if="can.viewPatient"
                        :href="showPatient(report.patient.id)"
                        class="font-medium underline-offset-4 hover:underline"
                    >
                        {{ report.patient.full_name }}
                    </Link>
                    <span v-else class="font-medium">{{
                        report.patient.full_name
                    }}</span>
                    <span class="ml-2 font-mono text-xs text-muted-foreground">
                        {{ report.patient.medical_record_number }}
                    </span>
                </div>
                <div
                    class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                >
                    <Badge
                        :variant="
                            report.status === 'released'
                                ? 'secondary'
                                : 'default'
                        "
                    >
                        {{ report.status_label }}
                    </Badge>
                    <span v-if="report.lab_name">{{ report.lab_name }} ·</span>
                    <span>Entered by {{ report.entered_by ?? '—' }}</span>
                    <span v-if="report.reviewed_by">
                        · Released by {{ report.reviewed_by }} on
                        {{ report.reviewed_at }}
                    </span>
                </div>
            </div>

            <Dialog v-if="can.release">
                <DialogTrigger as-child>
                    <Button>Release to patient</Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Release this report?</DialogTitle>
                        <DialogDescription>
                            The patient will be able to see these results. After
                            release, results can only be corrected by a doctor
                            or practitioner, and every correction is kept in the
                            history.
                        </DialogDescription>
                    </DialogHeader>
                    <InputError
                        :message="
                            (releaseForm.errors as Record<string, string>)
                                .release
                        "
                    />
                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button variant="secondary">Not yet</Button>
                        </DialogClose>
                        <Button
                            :disabled="releaseForm.processing"
                            @click="submitRelease"
                        >
                            Release
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>

        <a
            v-if="report.attachment"
            :href="report.attachment.url"
            download
            class="flex w-fit items-center gap-3 rounded-lg border px-3 py-2 text-sm hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <FileText class="size-4 text-muted-foreground" aria-hidden="true" />
            <span>
                <span class="font-medium">Original report</span>
                <span class="ml-2 text-muted-foreground">
                    {{ report.attachment.name }} ·
                    {{ formatSize(report.attachment.size) }}
                </span>
            </span>
            <Download class="size-4 text-muted-foreground" aria-hidden="true" />
        </a>

        <p v-if="report.notes" class="text-sm whitespace-pre-line">
            <span class="text-muted-foreground">Notes:</span> {{ report.notes }}
        </p>

        <p
            v-if="report.groups.length === 0"
            class="text-sm text-muted-foreground"
        >
            No results yet.
        </p>

        <section
            v-for="group in report.groups"
            :key="group.category"
            class="space-y-2"
        >
            <h2 class="text-base font-medium">{{ group.label }}</h2>
            <div class="overflow-x-auto rounded-lg border">
                <table class="w-full text-left text-sm">
                    <thead class="border-b bg-muted/50">
                        <tr>
                            <th class="px-3 py-2 font-medium">Biomarker</th>
                            <th class="px-3 py-2 font-medium">Result</th>
                            <th class="px-3 py-2 font-medium">Lab range</th>
                            <th class="px-3 py-2 font-medium">Flag</th>
                            <th class="px-3 py-2 font-medium">Comment</th>
                            <th class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template
                            v-for="result in group.results"
                            :key="result.id"
                        >
                            <tr class="border-b last:border-0">
                                <td class="px-3 py-2">
                                    {{ result.biomarker.name }}
                                </td>
                                <td class="px-3 py-2 font-medium">
                                    {{ result.value }}
                                    <span
                                        class="font-normal text-muted-foreground"
                                        >{{ result.unit }}</span
                                    >
                                </td>
                                <td class="px-3 py-2">
                                    {{ result.range ?? '—' }}
                                </td>
                                <td class="px-3 py-2">
                                    <FlagBadge
                                        :flag="result.flag"
                                        :label="result.flag_label"
                                    />
                                </td>
                                <td class="px-3 py-2 text-muted-foreground">
                                    {{ result.comment }}
                                </td>
                                <td
                                    class="px-3 py-2 text-right whitespace-nowrap"
                                >
                                    <Button
                                        v-if="result.history.length"
                                        variant="ghost"
                                        size="sm"
                                        @click="
                                            openHistory =
                                                openHistory === result.id
                                                    ? null
                                                    : result.id
                                        "
                                    >
                                        History ({{ result.history.length }})
                                    </Button>
                                    <CorrectResultDialog
                                        v-if="can.correct"
                                        :result="result"
                                    />
                                </td>
                            </tr>
                            <tr
                                v-if="openHistory === result.id"
                                class="border-b bg-muted/30"
                            >
                                <td colspan="6" class="px-3 py-2">
                                    <ul class="space-y-1 text-xs">
                                        <li>
                                            <span class="font-medium"
                                                >Current:</span
                                            >
                                            {{ result.value }}
                                            {{ result.unit }}, entered by
                                            {{ result.entered_by ?? '—' }} on
                                            {{ result.entered_at }}. Reason:
                                            {{ result.correction_reason }}
                                        </li>
                                        <li
                                            v-for="version in result.history"
                                            :key="version.id"
                                        >
                                            <span
                                                class="text-muted-foreground line-through"
                                            >
                                                {{ version.value }}
                                                {{ version.unit }}
                                            </span>
                                            entered by
                                            {{ version.entered_by ?? '—' }} on
                                            {{ version.entered_at }}
                                            <span
                                                v-if="version.correction_reason"
                                            >
                                                (reason:
                                                {{ version.correction_reason }})
                                            </span>
                                        </li>
                                    </ul>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </section>

        <section v-if="can.addResults" class="space-y-3">
            <h2 class="text-base font-medium">Add results</h2>
            <ResultRowsEditor
                v-model="addForm.results"
                :catalog="catalog"
                :errors="addForm.errors as Record<string, string | undefined>"
                :exclude="existing"
            />
            <Button :disabled="addForm.processing" @click="addResults">
                Add results
            </Button>
        </section>

        <p class="text-xs text-muted-foreground">
            Flags compare each value with the range printed on the lab's report.
            They are not a clinical interpretation.
        </p>
    </div>
</template>
