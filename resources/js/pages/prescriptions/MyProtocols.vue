<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { NotebookPen } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatDay, textareaClass } from '@/lib/prescriptions';
import { update } from '@/routes/my-protocols/adherence';
import type {
    AdherenceDay,
    PatientPrescription,
    PatientProtocolItem,
} from '@/types';

const props = defineProps<{
    linked: boolean;
    prescriptions: PatientPrescription[];
    // The days that can be ticked, oldest first, ending today.
    days: string[];
    today: string;
}>();

const page = usePage();
const dateError = computed(
    () => (page.props.errors as Record<string, string | undefined>).date,
);

const todayIndex = computed(() => props.days.indexOf(props.today));

function dayOf(
    item: PatientProtocolItem,
    date: string,
): AdherenceDay | undefined {
    return item.days.find((day) => day.date === date);
}

const doneToday = computed(() => {
    const items = props.prescriptions.flatMap((prescription) =>
        prescription.items.filter(
            (item) => dayOf(item, props.today)?.scheduled,
        ),
    );

    return {
        done: items.filter((item) => dayOf(item, props.today)?.taken).length,
        total: items.length,
    };
});

// "item:date" while that tick is being saved.
const saving = ref<string | null>(null);

function toggle(item: PatientProtocolItem, day: AdherenceDay): void {
    if (!day.scheduled || saving.value) {
        return;
    }

    saving.value = `${item.id}:${day.date}`;

    router.put(
        update(item.id).url,
        { date: day.date, taken: !day.taken, notes: day.notes ?? '' },
        {
            preserveScroll: true,
            onFinish: () => {
                saving.value = null;
            },
        },
    );
}

const noteOpen = ref(false);
const noteItem = ref<PatientProtocolItem | null>(null);
const noteForm = useForm({ date: '', taken: false, notes: '' });

function openNote(item: PatientProtocolItem, day: AdherenceDay): void {
    noteItem.value = item;
    noteForm.defaults({
        date: day.date,
        taken: day.taken,
        notes: day.notes ?? '',
    });
    noteForm.reset();
    noteForm.clearErrors();
    noteOpen.value = true;
}

function saveNote(): void {
    if (!noteItem.value) {
        return;
    }

    noteForm.put(update(noteItem.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            noteOpen.value = false;
        },
    });
}

function weekday(date: string): string {
    return formatDay(date, { weekday: 'short' });
}

function dayLabel(date: string): string {
    return formatDay(date, { weekday: 'long', day: 'numeric', month: 'long' });
}
</script>

<template>
    <Head title="My protocols" />

    <div class="flex h-full max-w-5xl flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">My protocols</h1>
            <p class="text-sm text-muted-foreground">
                Tick each item once you've done it for the day. You can catch up
                on the last {{ days.length }} days.
            </p>
        </div>

        <p
            v-if="!linked"
            class="rounded-lg border px-4 py-3 text-sm text-muted-foreground"
        >
            Your account isn't linked to a patient record yet. Please contact
            the clinic.
        </p>

        <p
            v-else-if="prescriptions.length === 0"
            class="rounded-lg border px-4 py-3 text-sm text-muted-foreground"
        >
            You have no protocols in effect today.
        </p>

        <template v-else>
            <div
                v-if="doneToday.total"
                class="flex items-center gap-3 rounded-lg border px-4 py-3"
            >
                <div class="text-sm">
                    <span class="font-medium">Today:</span>
                    {{ doneToday.done }} of {{ doneToday.total }} done
                </div>
                <div
                    class="h-2 flex-1 overflow-hidden rounded-full bg-muted"
                    role="progressbar"
                    :aria-valuenow="doneToday.done"
                    :aria-valuemax="doneToday.total"
                    aria-label="Items done today"
                >
                    <div
                        class="h-full rounded-full bg-primary transition-[width]"
                        :style="{
                            width: `${(doneToday.done / doneToday.total) * 100}%`,
                        }"
                    />
                </div>
            </div>

            <InputError :message="dateError" />

            <section
                v-for="prescription in prescriptions"
                :key="prescription.id"
                class="space-y-3"
            >
                <div>
                    <h2 class="text-base font-medium">
                        {{ prescription.title }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        Since {{ formatDay(prescription.starts_on) }}
                        <span v-if="prescription.ends_on">
                            · until {{ formatDay(prescription.ends_on) }}
                        </span>
                    </p>
                </div>

                <div class="overflow-x-auto rounded-lg border">
                    <table class="w-full min-w-[40rem] text-left text-sm">
                        <thead class="border-b bg-muted/50">
                            <tr>
                                <th class="px-3 py-2 font-medium">Item</th>
                                <th
                                    v-for="(date, index) in days"
                                    :key="date"
                                    class="w-12 px-1 py-2 text-center text-xs font-medium"
                                    :class="{
                                        'text-primary': index === todayIndex,
                                    }"
                                    :title="dayLabel(date)"
                                >
                                    {{
                                        index === todayIndex
                                            ? 'Today'
                                            : weekday(date)
                                    }}
                                    <div
                                        class="font-normal text-muted-foreground"
                                    >
                                        {{
                                            formatDay(date, { day: 'numeric' })
                                        }}
                                    </div>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="item in prescription.items"
                                :key="item.id"
                                class="border-b align-middle last:border-0"
                            >
                                <td class="px-3 py-2">
                                    <div class="font-medium">
                                        {{ item.name }}
                                        <span
                                            v-if="item.dosage"
                                            class="font-normal text-muted-foreground"
                                        >
                                            · {{ item.dosage }}
                                        </span>
                                    </div>
                                    <div class="text-xs text-muted-foreground">
                                        {{ item.type_label }} ·
                                        {{ item.frequency_label }}
                                    </div>
                                    <div
                                        v-if="item.instructions"
                                        class="text-xs"
                                    >
                                        {{ item.instructions }}
                                    </div>
                                </td>
                                <td
                                    v-for="day in item.days"
                                    :key="day.date"
                                    class="px-1 py-2 text-center"
                                >
                                    <div
                                        v-if="day.scheduled"
                                        class="flex flex-col items-center gap-1"
                                    >
                                        <Checkbox
                                            :model-value="day.taken"
                                            :disabled="saving !== null"
                                            class="size-5"
                                            :aria-label="`${item.name}, ${dayLabel(day.date)}`"
                                            @update:model-value="
                                                toggle(item, day)
                                            "
                                        />
                                        <button
                                            type="button"
                                            class="rounded text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                            :class="{
                                                'text-primary': day.notes,
                                            }"
                                            :aria-label="`${day.notes ? 'Edit' : 'Add'} a note for ${item.name}, ${dayLabel(day.date)}`"
                                            :title="day.notes ?? 'Add a note'"
                                            @click="openNote(item, day)"
                                        >
                                            <NotebookPen class="size-3.5" />
                                        </button>
                                    </div>
                                    <span
                                        v-else
                                        class="text-muted-foreground"
                                        title="Not part of your protocol that day"
                                        >–</span
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <p class="text-xs text-muted-foreground">
                Your notes are shared with your care team. If something feels
                wrong, contact the clinic rather than waiting for them to read a
                note.
            </p>
        </template>

        <Dialog v-model:open="noteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Note for {{ noteItem?.name }}</DialogTitle>
                    <DialogDescription>
                        {{ noteForm.date ? dayLabel(noteForm.date) : '' }}. For
                        example, a missed dose or how you felt.
                    </DialogDescription>
                </DialogHeader>
                <div class="grid gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox v-model="noteForm.taken" />
                        Done this day
                    </label>
                    <div class="grid gap-2">
                        <Label for="adherence-note">Note</Label>
                        <textarea
                            id="adherence-note"
                            v-model="noteForm.notes"
                            rows="3"
                            maxlength="500"
                            :class="textareaClass"
                        />
                        <InputError
                            :message="
                                noteForm.errors.notes ?? noteForm.errors.date
                            "
                        />
                    </div>
                </div>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button :disabled="noteForm.processing" @click="saveNote">
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
