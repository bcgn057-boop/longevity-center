<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { cancel } from '@/routes/lab-requisitions';
import { store } from '@/routes/patients/lab-requisitions';
import type { LabPartnerOption, LabRequisitionRow } from '@/types';

const props = defineProps<{
    patientId: number;
    requisitions: LabRequisitionRow[];
    labPartners: LabPartnerOption[];
    canOrder: boolean;
}>();

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30';
const textareaClass =
    'w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30';

const form = useForm<{
    lab_partner_id: string;
    tests: string[];
    instructions: string;
}>({ lab_partner_id: '', tests: [], instructions: '' });

const partner = computed(() =>
    props.labPartners.find((p) => p.id.toString() === form.lab_partner_id),
);

// Tests belong to one lab: changing lab clears the choice.
watch(
    () => form.lab_partner_id,
    () => {
        form.tests = [];
    },
);

const cancelForm = useForm({});

function submit(): void {
    form.post(store(props.patientId).url, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <section class="space-y-4">
        <div>
            <h2 class="text-base font-medium">Lab requisitions</h2>
            <p class="text-sm text-muted-foreground">
                Each partner lab only sees the requisitions sent to it, and the
                patient on them.
            </p>
        </div>

        <ul
            v-if="requisitions.length"
            class="divide-y rounded-lg border text-sm"
        >
            <li
                v-for="requisition in requisitions"
                :key="requisition.id"
                class="flex flex-wrap items-start justify-between gap-3 px-4 py-3"
            >
                <div class="space-y-1">
                    <div class="font-medium">
                        {{ requisition.lab_partner.name }}:
                        {{ requisition.tests.join(', ') }}
                    </div>
                    <div
                        v-if="requisition.instructions"
                        class="text-muted-foreground"
                    >
                        {{ requisition.instructions }}
                    </div>
                    <div class="text-xs text-muted-foreground">
                        Ordered {{ requisition.ordered_at }} by
                        {{ requisition.ordered_by ?? '—' }}
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <Badge
                        :variant="
                            requisition.status === 'ordered'
                                ? 'default'
                                : 'secondary'
                        "
                    >
                        {{ requisition.status_label }}
                    </Badge>
                    <Button
                        v-if="canOrder && requisition.status === 'ordered'"
                        size="sm"
                        variant="ghost"
                        :disabled="cancelForm.processing"
                        @click="
                            cancelForm.post(cancel(requisition.id).url, {
                                preserveScroll: true,
                            })
                        "
                    >
                        Cancel
                    </Button>
                </div>
            </li>
        </ul>
        <p v-else class="text-sm text-muted-foreground">No requisitions yet.</p>

        <form
            v-if="canOrder"
            class="space-y-3 rounded-lg border p-4"
            @submit.prevent="submit"
        >
            <h3 class="text-sm font-medium">Order tests</h3>
            <div class="grid gap-2">
                <Label for="requisition-lab">Lab partner</Label>
                <select
                    id="requisition-lab"
                    v-model="form.lab_partner_id"
                    required
                    :class="selectClass"
                >
                    <option value="" disabled>Choose a lab…</option>
                    <option
                        v-for="option in labPartners"
                        :key="option.id"
                        :value="option.id.toString()"
                    >
                        {{ option.name }}
                    </option>
                </select>
                <InputError :message="form.errors.lab_partner_id" />
            </div>
            <fieldset v-if="partner" class="grid gap-2">
                <legend class="mb-1 text-sm font-medium">Tests</legend>
                <label
                    v-for="test in partner.tests"
                    :key="test.code"
                    class="flex items-center gap-2 text-sm"
                >
                    <input
                        v-model="form.tests"
                        type="checkbox"
                        :value="test.code"
                        class="size-4 accent-primary"
                    />
                    {{ test.label }}
                </label>
                <InputError :message="form.errors.tests" />
            </fieldset>
            <div class="grid gap-2">
                <Label for="requisition-instructions">
                    Instructions for the lab (optional)
                </Label>
                <textarea
                    id="requisition-instructions"
                    v-model="form.instructions"
                    rows="2"
                    maxlength="1000"
                    :class="textareaClass"
                />
                <InputError :message="form.errors.instructions" />
            </div>
            <Button type="submit" :disabled="form.processing || !partner">
                Order tests
            </Button>
        </form>
    </section>
</template>
