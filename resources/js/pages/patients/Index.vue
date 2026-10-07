<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { create, index, show } from '@/routes/patients';
import type { Paginated, PatientListItem } from '@/types';

const props = defineProps<{
    patients: Paginated<PatientListItem>;
    filters: { search: string };
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Patients', href: index() }],
    },
});

const search = ref(props.filters.search);

function submitSearch(): void {
    const term = search.value.trim();

    router.get(index().url, term ? { search: term } : {}, {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Patients" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Patients</h1>
                <p class="text-sm text-muted-foreground">
                    {{ patients.total }} patients. Every search and record view
                    is recorded in the audit log.
                </p>
            </div>
            <Button v-if="can.create" as-child>
                <Link :href="create()">New patient</Link>
            </Button>
        </div>

        <form
            :action="index().url"
            method="get"
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="submitSearch"
        >
            <div class="grid gap-1.5">
                <Label for="search">Search</Label>
                <Input
                    id="search"
                    v-model="search"
                    name="search"
                    placeholder="Name or record number"
                    maxlength="100"
                    class="w-72"
                />
            </div>
            <Button type="submit">Search</Button>
        </form>

        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Record no.</th>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Sex</th>
                        <th class="px-3 py-2 font-medium">Account</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="patients.data.length === 0">
                        <td
                            colspan="4"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            No patients found.
                        </td>
                    </tr>
                    <tr
                        v-for="patient in patients.data"
                        :key="patient.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ patient.medical_record_number }}
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                :href="show(patient.id)"
                                class="font-medium underline-offset-4 hover:underline"
                            >
                                {{ patient.full_name }}
                            </Link>
                        </td>
                        <td class="px-3 py-2">{{ patient.sex_label }}</td>
                        <td class="px-3 py-2">
                            <Badge
                                :variant="
                                    patient.has_account ? 'default' : 'outline'
                                "
                            >
                                {{ patient.has_account ? 'Linked' : 'None' }}
                            </Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="patients.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <span class="text-muted-foreground">
                Page {{ patients.current_page }} of {{ patients.last_page }}
            </span>
            <div class="flex gap-2">
                <Button
                    v-if="patients.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="patients.prev_page_url" preserve-scroll>
                        Previous
                    </Link>
                </Button>
                <Button
                    v-if="patients.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="patients.next_page_url" preserve-scroll>
                        Next
                    </Link>
                </Button>
            </div>
        </div>
    </div>
</template>
