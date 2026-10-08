<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
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
import { store } from '@/routes/lab-results/corrections';
import type { LabResultItem } from '@/types';

const props = defineProps<{
    result: LabResultItem;
}>();

const open = ref(false);

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30';

const form = useForm({
    value: props.result.value,
    unit: props.result.unit,
    reference_low: props.result.reference_low ?? '',
    reference_high: props.result.reference_high ?? '',
    comment: props.result.comment ?? '',
    reason: '',
});

function submit(): void {
    form.post(store(props.result.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="ghost" size="sm">Correct</Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Correct {{ result.biomarker.name }}</DialogTitle>
                <DialogDescription>
                    The current value is kept in the history. The correction and
                    its reason are recorded with your name.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="correct-value">Result</Label>
                        <Input
                            id="correct-value"
                            v-model="form.value"
                            maxlength="32"
                        />
                        <InputError :message="form.errors.value" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="correct-unit">Unit</Label>
                        <select
                            id="correct-unit"
                            v-model="form.unit"
                            :class="selectClass"
                        >
                            <option
                                v-for="unit in result.biomarker.units"
                                :key="unit"
                                :value="unit"
                            >
                                {{ unit }}
                            </option>
                        </select>
                        <InputError :message="form.errors.unit" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="correct-low">Lab range low</Label>
                        <Input
                            id="correct-low"
                            v-model="form.reference_low"
                            inputmode="decimal"
                        />
                        <InputError :message="form.errors.reference_low" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="correct-high">Lab range high</Label>
                        <Input
                            id="correct-high"
                            v-model="form.reference_high"
                            inputmode="decimal"
                        />
                        <InputError :message="form.errors.reference_high" />
                    </div>
                </div>
                <div class="grid gap-2">
                    <Label for="correct-comment">Comment</Label>
                    <Input
                        id="correct-comment"
                        v-model="form.comment"
                        maxlength="500"
                    />
                    <InputError :message="form.errors.comment" />
                </div>
                <div class="grid gap-2">
                    <Label for="correct-reason">Reason for correction</Label>
                    <Input
                        id="correct-reason"
                        v-model="form.reason"
                        maxlength="500"
                        required
                        placeholder="e.g. Transcription error"
                    />
                    <InputError :message="form.errors.reason" />
                </div>
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button :disabled="form.processing" @click="submit">
                    Save correction
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
