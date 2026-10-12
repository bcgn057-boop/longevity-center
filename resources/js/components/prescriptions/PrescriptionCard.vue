<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { History, Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import AdjustItemDialog from '@/components/prescriptions/AdjustItemDialog.vue';
import ProtocolItemFields from '@/components/prescriptions/ProtocolItemFields.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    emptyItemRow,
    formatDateTime,
    formatDay,
    textareaClass,
} from '@/lib/prescriptions';
import { discontinue, update } from '@/routes/prescriptions';
import { store as storeItem } from '@/routes/prescriptions/items';
import { discontinue as stopItem } from '@/routes/protocol-items';
import type {
    PrescriptionOptions,
    StaffPrescription,
    StaffProtocolItem,
} from '@/types';

const props = defineProps<{
    prescription: StaffPrescription;
    options: PrescriptionOptions;
    canManage: boolean;
}>();

const manageable = computed(
    () => props.canManage && props.prescription.status !== 'discontinued',
);

const byId = computed(
    () => new Map(props.prescription.items.map((item) => [item.id, item])),
);
const replaced = computed(
    () =>
        new Set(
            props.prescription.items
                .map((item) => item.supersedes_id)
                .filter((id): id is number => id !== null),
        ),
);
const currentItems = computed(() =>
    props.prescription.items.filter((item) => item.current),
);
// Stopped outright, not replaced by an adjustment.
const stoppedItems = computed(() =>
    props.prescription.items.filter(
        (item) => !item.current && !replaced.value.has(item.id),
    ),
);

// Earlier versions of an item, newest first.
function history(item: StaffProtocolItem): StaffProtocolItem[] {
    const versions: StaffProtocolItem[] = [];
    let previous =
        item.supersedes_id !== null ? byId.value.get(item.supersedes_id) : null;

    while (previous) {
        versions.push(previous);
        previous =
            previous.supersedes_id !== null
                ? byId.value.get(previous.supersedes_id)
                : null;
    }

    return versions;
}

function adherenceRate(item: StaffProtocolItem): number | null {
    return item.adherence.scheduled === 0
        ? null
        : Math.round((item.adherence.taken / item.adherence.scheduled) * 100);
}

function statusVariant(): 'default' | 'secondary' | 'outline' {
    return props.prescription.status === 'active'
        ? 'default'
        : props.prescription.status === 'completed'
          ? 'secondary'
          : 'outline';
}

const openHistory = ref<number | null>(null);

const editOpen = ref(false);
const editForm = useForm({
    title: props.prescription.title,
    notes: props.prescription.notes ?? '',
    ends_on: props.prescription.ends_on ?? '',
});

function openEdit(): void {
    editForm.defaults({
        title: props.prescription.title,
        notes: props.prescription.notes ?? '',
        ends_on: props.prescription.ends_on ?? '',
    });
    editForm.reset();
    editForm.clearErrors();
}

function submitEdit(): void {
    editForm
        .transform((data) => ({ ...data, ends_on: data.ends_on || null }))
        .put(update(props.prescription.id).url, {
            preserveScroll: true,
            onSuccess: () => {
                editOpen.value = false;
            },
        });
}

const discontinueOpen = ref(false);
const discontinueForm = useForm({ reason: '' });

function submitDiscontinue(): void {
    discontinueForm.post(discontinue(props.prescription.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            discontinueOpen.value = false;
            discontinueForm.reset();
        },
    });
}

const addOpen = ref(false);
const addForm = useForm(emptyItemRow());

function submitAdd(): void {
    addForm.post(storeItem(props.prescription.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            addOpen.value = false;
            addForm.reset();
        },
    });
}

const adjusting = ref<StaffProtocolItem | null>(null);
const adjustOpen = ref(false);

function openAdjust(item: StaffProtocolItem): void {
    adjusting.value = item;
    adjustOpen.value = true;
}

const stopping = ref<StaffProtocolItem | null>(null);
const stopOpen = ref(false);
const stopForm = useForm({});

function openStop(item: StaffProtocolItem): void {
    stopping.value = item;
    stopForm.clearErrors();
    stopOpen.value = true;
}

function submitStop(): void {
    if (!stopping.value) {
        return;
    }

    stopForm.post(stopItem(stopping.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            stopOpen.value = false;
        },
    });
}
</script>

<template>
    <article class="space-y-4 rounded-lg border p-4">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div class="space-y-1">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-base font-semibold">
                        {{ prescription.title }}
                    </h2>
                    <Badge :variant="statusVariant()">
                        {{ prescription.status_label }}
                    </Badge>
                </div>
                <p class="text-sm text-muted-foreground">
                    {{ formatDay(prescription.starts_on) }} –
                    {{
                        prescription.ends_on
                            ? formatDay(prescription.ends_on)
                            : 'ongoing'
                    }}
                    · Prescribed by {{ prescription.prescriber ?? '—' }}
                </p>
            </div>

            <div v-if="manageable" class="flex flex-wrap gap-2">
                <Dialog v-model:open="addOpen">
                    <DialogTrigger as-child>
                        <Button variant="outline" size="sm">
                            <Plus class="size-4" /> Add item
                        </Button>
                    </DialogTrigger>
                    <DialogContent class="sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>Add an item</DialogTitle>
                            <DialogDescription>
                                The patient sees it on their dashboard from
                                today.
                            </DialogDescription>
                        </DialogHeader>
                        <ProtocolItemFields
                            :model-value="addForm"
                            :options="options"
                            :errors="
                                addForm.errors as Record<
                                    string,
                                    string | undefined
                                >
                            "
                            :id-prefix="`add-${prescription.id}`"
                        />
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button
                                :disabled="addForm.processing"
                                @click="submitAdd"
                            >
                                Add item
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog v-model:open="editOpen">
                    <DialogTrigger as-child>
                        <Button variant="outline" size="sm" @click="openEdit">
                            Edit
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Edit prescription</DialogTitle>
                            <DialogDescription>
                                To change an item, adjust it instead so its
                                history is kept.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="grid gap-4">
                            <div class="grid gap-2">
                                <Label :for="`edit-title-${prescription.id}`">
                                    Title
                                </Label>
                                <Input
                                    :id="`edit-title-${prescription.id}`"
                                    v-model="editForm.title"
                                    maxlength="120"
                                    required
                                />
                                <InputError :message="editForm.errors.title" />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`edit-ends-${prescription.id}`">
                                    Ends (optional)
                                </Label>
                                <Input
                                    :id="`edit-ends-${prescription.id}`"
                                    v-model="editForm.ends_on"
                                    type="date"
                                    :min="prescription.starts_on"
                                />
                                <InputError
                                    :message="editForm.errors.ends_on"
                                />
                            </div>
                            <div class="grid gap-2">
                                <Label :for="`edit-notes-${prescription.id}`">
                                    Clinical notes (staff only)
                                </Label>
                                <textarea
                                    :id="`edit-notes-${prescription.id}`"
                                    v-model="editForm.notes"
                                    rows="4"
                                    maxlength="2000"
                                    :class="textareaClass"
                                />
                                <InputError :message="editForm.errors.notes" />
                            </div>
                        </div>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary">Cancel</Button>
                            </DialogClose>
                            <Button
                                :disabled="editForm.processing"
                                @click="submitEdit"
                            >
                                Save
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Dialog v-model:open="discontinueOpen">
                    <DialogTrigger as-child>
                        <Button variant="outline" size="sm">Discontinue</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle
                                >Discontinue this prescription?</DialogTitle
                            >
                            <DialogDescription>
                                Every item stops today and the patient no longer
                                sees it. This cannot be undone; issue a new
                                prescription to restart. The reason is recorded
                                with your name.
                            </DialogDescription>
                        </DialogHeader>
                        <div class="grid gap-2">
                            <Label :for="`discontinue-${prescription.id}`">
                                Reason
                            </Label>
                            <Input
                                :id="`discontinue-${prescription.id}`"
                                v-model="discontinueForm.reason"
                                maxlength="500"
                                required
                                placeholder="e.g. Side effects"
                            />
                            <InputError
                                :message="discontinueForm.errors.reason"
                            />
                        </div>
                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary">Keep it</Button>
                            </DialogClose>
                            <Button
                                variant="destructive"
                                :disabled="discontinueForm.processing"
                                @click="submitDiscontinue"
                            >
                                Discontinue
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </header>

        <p v-if="prescription.notes" class="text-sm whitespace-pre-line">
            <span class="text-muted-foreground">Notes:</span>
            {{ prescription.notes }}
        </p>
        <p
            v-if="prescription.status === 'discontinued'"
            class="rounded-md bg-muted/50 px-3 py-2 text-sm"
        >
            Discontinued {{ formatDateTime(prescription.discontinued_at) }}.
            Reason: {{ prescription.discontinued_reason ?? '—' }}
        </p>

        <div
            v-if="currentItems.length"
            class="overflow-x-auto rounded-lg border"
        >
            <table class="w-full min-w-[44rem] text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Item</th>
                        <th class="px-3 py-2 font-medium">Dose</th>
                        <th class="px-3 py-2 font-medium">How often</th>
                        <th class="px-3 py-2 font-medium">Last 30 days</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="item in currentItems" :key="item.id">
                        <tr class="border-b align-top last:border-0">
                            <td class="px-3 py-2">
                                <div class="font-medium">{{ item.name }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ item.type_label }}
                                    <span v-if="item.instructions">
                                        · {{ item.instructions }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-3 py-2">{{ item.dosage ?? '—' }}</td>
                            <td class="px-3 py-2">
                                {{ item.frequency_label }}
                            </td>
                            <td class="px-3 py-2">
                                <template v-if="adherenceRate(item) !== null">
                                    <div class="flex items-center gap-2">
                                        <div
                                            class="h-1.5 w-20 overflow-hidden rounded-full bg-muted"
                                            role="presentation"
                                        >
                                            <div
                                                class="h-full rounded-full bg-primary"
                                                :style="{
                                                    width: `${adherenceRate(item)}%`,
                                                }"
                                            />
                                        </div>
                                        <span class="tabular-nums">
                                            {{ adherenceRate(item) }}%
                                        </span>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ item.adherence.taken }} of
                                        {{ item.adherence.scheduled }} days
                                        ticked
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground">
                                    Not started
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <Button
                                    v-if="history(item).length"
                                    variant="ghost"
                                    size="sm"
                                    :aria-expanded="openHistory === item.id"
                                    @click="
                                        openHistory =
                                            openHistory === item.id
                                                ? null
                                                : item.id
                                    "
                                >
                                    <History class="size-4" />
                                    {{ history(item).length }}
                                </Button>
                                <template v-if="manageable">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="openAdjust(item)"
                                    >
                                        Adjust
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="openStop(item)"
                                    >
                                        Stop
                                    </Button>
                                </template>
                            </td>
                        </tr>
                        <tr
                            v-if="openHistory === item.id"
                            class="border-b bg-muted/30"
                        >
                            <td colspan="5" class="px-3 py-2">
                                <ul class="space-y-1 text-xs">
                                    <li>
                                        <span class="font-medium"
                                            >Current:</span
                                        >
                                        {{ item.dosage ?? '—' }},
                                        {{ item.frequency_label }}, since
                                        {{ formatDateTime(item.created_at) }}.
                                        Reason: {{ item.adjustment_reason }}
                                    </li>
                                    <li
                                        v-for="version in history(item)"
                                        :key="version.id"
                                    >
                                        <span
                                            class="text-muted-foreground line-through"
                                        >
                                            {{ version.name }}
                                            {{ version.dosage ?? '' }},
                                            {{ version.frequency_label }}
                                        </span>
                                        from
                                        {{ formatDateTime(version.created_at) }}
                                        to
                                        {{
                                            formatDateTime(
                                                version.discontinued_at,
                                            )
                                        }}
                                        <span v-if="version.adjustment_reason">
                                            (reason:
                                            {{ version.adjustment_reason }})
                                        </span>
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p
            v-else-if="prescription.status !== 'discontinued'"
            class="text-sm text-muted-foreground"
        >
            No current items.
        </p>

        <details v-if="stoppedItems.length" class="text-sm">
            <summary class="cursor-pointer text-muted-foreground">
                Stopped items ({{ stoppedItems.length }})
            </summary>
            <ul class="mt-2 space-y-1 pl-4 text-xs">
                <li v-for="item in stoppedItems" :key="item.id">
                    <span class="font-medium">{{ item.name }}</span>
                    {{ item.dosage ?? '' }}, {{ item.frequency_label }} ·
                    {{ formatDateTime(item.created_at) }} to
                    {{ formatDateTime(item.discontinued_at) }}
                </li>
            </ul>
        </details>

        <AdjustItemDialog
            v-if="manageable"
            v-model:open="adjustOpen"
            :item="adjusting"
            :options="options"
        />

        <Dialog v-if="manageable" v-model:open="stopOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Stop {{ stopping?.name }}?</DialogTitle>
                    <DialogDescription>
                        The patient stops seeing it from today. Its history and
                        adherence are kept.
                    </DialogDescription>
                </DialogHeader>
                <InputError
                    :message="(stopForm.errors as Record<string, string>).item"
                />
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Keep it</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="stopForm.processing"
                        @click="submitStop"
                    >
                        Stop item
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </article>
</template>
