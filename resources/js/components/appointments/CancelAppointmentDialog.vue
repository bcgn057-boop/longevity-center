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
import { Label } from '@/components/ui/label';

const props = defineProps<{
    url: string;
    description: string;
    // Only users allowed to read reasons may give one.
    canGiveReason: boolean;
}>();

const open = ref(false);

const form = useForm<{ cancellation_reason?: string }>(
    props.canGiveReason ? { cancellation_reason: '' } : {},
);

function submit(): void {
    form.post(props.url, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
            form.reset();
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button variant="destructive" size="sm">Cancel appointment</Button>
        </DialogTrigger>
        <DialogContent>
            <DialogHeader>
                <DialogTitle>Cancel this appointment?</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>

            <div v-if="canGiveReason" class="grid gap-2">
                <Label for="cancellation_reason">Reason (optional)</Label>
                <textarea
                    id="cancellation_reason"
                    v-model="form.cancellation_reason"
                    rows="3"
                    maxlength="500"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs dark:bg-input/30"
                />
                <InputError :message="form.errors.cancellation_reason" />
            </div>
            <InputError
                :message="(form.errors as Record<string, string>).status"
            />

            <DialogFooter class="gap-2">
                <DialogClose as-child>
                    <Button variant="secondary">Keep appointment</Button>
                </DialogClose>
                <Button
                    variant="destructive"
                    :disabled="form.processing"
                    @click="submit"
                >
                    Cancel appointment
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
