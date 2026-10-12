<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import InputError from '@/components/InputError.vue';
import ProtocolItemFields from '@/components/prescriptions/ProtocolItemFields.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { adjust } from '@/routes/protocol-items';
import type { PrescriptionOptions, StaffProtocolItem } from '@/types';

const props = defineProps<{
    item: StaffProtocolItem | null;
    options: PrescriptionOptions;
}>();

const open = defineModel<boolean>('open', { required: true });

const form = useForm({
    type: '',
    name: '',
    dosage: '',
    instructions: '',
    frequency: '',
    reason: '',
});

// Start from the item's current details each time the dialog opens.
watch(open, (isOpen) => {
    if (!isOpen || !props.item) {
        return;
    }

    form.defaults({
        type: props.item.type,
        name: props.item.name,
        dosage: props.item.dosage ?? '',
        instructions: props.item.instructions ?? '',
        frequency: props.item.frequency,
        reason: '',
    });
    form.reset();
    form.clearErrors();
});

function submit(): void {
    if (!props.item) {
        return;
    }

    form.post(adjust(props.item.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Adjust {{ item?.name }}</DialogTitle>
                <DialogDescription>
                    The current version is kept in the history and the patient
                    follows the new one from today. The reason is recorded with
                    your name and is not shown to the patient.
                </DialogDescription>
            </DialogHeader>

            <div class="grid gap-4">
                <!-- The fields edit the form in place. -->
                <ProtocolItemFields
                    :model-value="form"
                    :options="options"
                    :errors="form.errors as Record<string, string | undefined>"
                    id-prefix="adjust"
                />
                <div class="grid gap-2">
                    <Label for="adjust-reason">Reason for the adjustment</Label>
                    <Input
                        id="adjust-reason"
                        v-model="form.reason"
                        maxlength="500"
                        required
                        placeholder="e.g. Level still low on retest"
                    />
                    <InputError :message="form.errors.reason" />
                </div>
            </div>

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Cancel</Button>
                </DialogClose>
                <Button :disabled="form.processing" @click="submit">
                    Save adjustment
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
