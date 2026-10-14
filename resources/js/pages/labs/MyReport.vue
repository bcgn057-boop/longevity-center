<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Download, FileText } from '@lucide/vue';
import FlagBadge from '@/components/labs/FlagBadge.vue';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/my-results';
import type { PatientReport } from '@/types';

defineProps<{
    report: PatientReport;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'My results', href: index() }],
    },
});
</script>

<template>
    <Head title="Lab report" />

    <div class="flex h-full max-w-4xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">
                Lab report: {{ report.collected }}
            </h1>
            <p class="text-sm text-muted-foreground">
                <span v-if="report.lab_name">{{ report.lab_name }} · </span>
                Reviewed and released {{ report.released }}
            </p>
        </div>

        <div
            v-if="report.attachment_url"
            class="flex flex-wrap items-center gap-3 rounded-lg border px-4 py-3 text-sm"
        >
            <FileText class="size-4 text-muted-foreground" aria-hidden="true" />
            <span class="flex-1">
                The lab's original report is available as a PDF. If a result was
                corrected after release, the corrected value below is the one
                that counts.
            </span>
            <Button variant="outline" size="sm" as-child>
                <a :href="report.attachment_url" download>
                    <Download class="size-4" aria-hidden="true" />
                    Download PDF
                </a>
            </Button>
        </div>

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
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="result in group.results"
                            :key="result.biomarker"
                            class="border-b last:border-0"
                        >
                            <td class="px-3 py-2">{{ result.biomarker }}</td>
                            <td class="px-3 py-2">
                                <span class="font-medium">{{
                                    result.value
                                }}</span>
                                {{ result.unit }}
                                <span
                                    v-if="result.updated"
                                    class="text-xs text-muted-foreground"
                                    >(updated)</span
                                >
                            </td>
                            <td class="px-3 py-2">
                                <template
                                    v-if="
                                        result.reference_low !== null ||
                                        result.reference_high !== null
                                    "
                                >
                                    {{ result.reference_low ?? '…' }}–{{
                                        result.reference_high ?? '…'
                                    }}
                                </template>
                                <template v-else>—</template>
                            </td>
                            <td class="px-3 py-2">
                                <FlagBadge
                                    :flag="result.flag"
                                    :label="result.flag_label"
                                />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <p class="text-xs text-muted-foreground">
            Flags compare each value with the range printed by the lab and are
            not a diagnosis. Please talk to your clinician about your results.
        </p>
    </div>
</template>
