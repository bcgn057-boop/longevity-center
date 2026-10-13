<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { ReportPeriod } from '@/types';

const props = defineProps<{
    period: ReportPeriod;
    url: string;
}>();

const page = usePage();
const errors = computed(
    () => page.props.errors as Record<string, string | undefined>,
);

const from = ref(props.period.from);
const to = ref(props.period.to);

function apply(): void {
    router.get(
        props.url,
        { from: from.value, to: to.value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
        <div class="grid gap-1.5">
            <Label for="period-from">From</Label>
            <Input id="period-from" v-model="from" type="date" required />
        </div>
        <div class="grid gap-1.5">
            <Label for="period-to">To</Label>
            <Input
                id="period-to"
                v-model="to"
                type="date"
                :min="from"
                required
            />
        </div>
        <Button type="submit">Update</Button>
        <p class="basis-full text-xs text-muted-foreground">
            {{ period.days }} days, counted in the clinic's timezone. Up to a
            year at a time.
        </p>
        <InputError class="basis-full" :message="errors.to ?? errors.from" />
    </form>
</template>
