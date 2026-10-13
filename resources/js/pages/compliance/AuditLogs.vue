<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Download } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    exportMethod as exportLogs,
    index,
} from '@/routes/compliance/audit-logs';
import type { AuditLogEntry, AuditLogFilters, StaffOption } from '@/types';

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    logs: Paginated<AuditLogEntry>;
    filters: AuditLogFilters;
    events: { value: string; category: string }[];
    categories: { value: string; label: string }[];
    staff: StaffOption[];
    can: { export: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audit Log', href: index() }],
    },
});

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30';

const form = ref({
    category: props.filters.category ?? '',
    event: props.filters.event ?? '',
    user_id: props.filters.user_id?.toString() ?? '',
    patient_id: props.filters.patient_id?.toString() ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
});

const eventOptions = computed(() =>
    form.value.category
        ? props.events.filter((event) => event.category === form.value.category)
        : props.events,
);

const categoryLabels = computed(
    () =>
        new Map(
            props.categories.map((category) => [
                category.value,
                category.label,
            ]),
        ),
);

// Filtering and paging are partial reloads: only these props are refetched,
// and the server does not record them as new views of the audit log.
const reloadProps = ['logs', 'filters'];

function query(values: Record<string, string>): Record<string, string> {
    return Object.fromEntries(
        Object.entries(values)
            .map(([key, value]) => [key, String(value ?? '').trim()])
            .filter(([, value]) => value !== ''),
    );
}

function reload(values: Record<string, string>): void {
    router.get(index().url, query(values), {
        only: reloadProps,
        preserveState: true,
        preserveScroll: true,
    });
}

function chooseCategory(): void {
    // Keep the event only if it is in the new category.
    if (!eventOptions.value.some((event) => event.value === form.value.event)) {
        form.value.event = '';
    }
}

function applyFilters(): void {
    reload(form.value);
}

function resetFilters(): void {
    form.value = {
        category: '',
        event: '',
        user_id: '',
        patient_id: '',
        from: '',
        to: '',
    };
    reload({});
}

// Exports use the filters last applied, which are the ones shown in the list.
function exportUrl(format: 'csv' | 'json'): string {
    const applied = Object.fromEntries(
        Object.entries(props.filters).map(([key, value]) => [
            key,
            String(value),
        ]),
    );

    return exportLogs({ query: { ...applied, format } }).url;
}

function details(entry: AuditLogEntry): string | null {
    const parts: Record<string, unknown> = {};

    if (entry.old_values) {
        parts.old = entry.old_values;
    }

    if (entry.new_values) {
        parts.new = entry.new_values;
    }

    if (entry.metadata) {
        parts.metadata = entry.metadata;
    }

    return Object.keys(parts).length ? JSON.stringify(parts, null, 2) : null;
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString();
}
</script>

<template>
    <Head title="Audit Log" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Audit Log</h1>
                <p class="text-sm text-muted-foreground">
                    Append-only record of security events and access to patient
                    data. Entries cannot be edited or deleted.
                    {{ logs.total }} matching entries.
                </p>
            </div>
            <div v-if="can.export" class="flex gap-2">
                <Button variant="outline" size="sm" as-child>
                    <a :href="exportUrl('csv')" download>
                        <Download class="size-4" /> CSV
                    </a>
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <a :href="exportUrl('json')" download>
                        <Download class="size-4" /> JSON
                    </a>
                </Button>
            </div>
        </div>

        <!-- action/method make the form work as a plain GET even if the
             Inertia handler is bypassed; normally it is a partial reload. -->
        <form
            :action="index().url"
            method="get"
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-1.5">
                <Label for="category">Category</Label>
                <select
                    id="category"
                    v-model="form.category"
                    name="category"
                    :class="selectClass"
                    @change="chooseCategory"
                >
                    <option value="">All categories</option>
                    <option
                        v-for="category in categories"
                        :key="category.value"
                        :value="category.value"
                    >
                        {{ category.label }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <Label for="event">Event</Label>
                <select
                    id="event"
                    v-model="form.event"
                    name="event"
                    :class="selectClass"
                >
                    <option value="">All events</option>
                    <option
                        v-for="event in eventOptions"
                        :key="event.value"
                        :value="event.value"
                    >
                        {{ event.value }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <Label for="user_id">Staff member</Label>
                <select
                    id="user_id"
                    v-model="form.user_id"
                    name="user_id"
                    :class="selectClass"
                >
                    <option value="">Anyone</option>
                    <option
                        v-for="member in staff"
                        :key="member.id"
                        :value="String(member.id)"
                    >
                        {{ member.name }} ({{ member.role }})
                    </option>
                    <option
                        v-if="
                            form.user_id &&
                            !staff.some((m) => String(m.id) === form.user_id)
                        "
                        :value="form.user_id"
                    >
                        User #{{ form.user_id }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <Label for="patient_id">Patient ID</Label>
                <Input
                    id="patient_id"
                    v-model="form.patient_id"
                    name="patient_id"
                    type="number"
                    min="1"
                    class="w-28"
                    title="Every entry about this patient's records"
                />
            </div>

            <div class="grid gap-1.5">
                <Label for="from">From</Label>
                <Input id="from" v-model="form.from" name="from" type="date" />
            </div>
            <div class="grid gap-1.5">
                <Label for="to">To</Label>
                <Input
                    id="to"
                    v-model="form.to"
                    name="to"
                    type="date"
                    :min="form.from || undefined"
                />
            </div>

            <Button type="submit">Filter</Button>
            <Button type="button" variant="outline" @click="resetFilters">
                Reset
            </Button>
        </form>
        <p class="text-xs text-muted-foreground">
            Dates are whole days in the clinic's timezone. The patient filter
            finds every entry about that patient's record, appointments, lab
            results and prescriptions. Exports are recorded in this log.
        </p>

        <div class="overflow-x-auto rounded-xl border border-sidebar-border/70">
            <table class="w-full text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">#</th>
                        <th class="px-3 py-2 font-medium">Time</th>
                        <th class="px-3 py-2 font-medium">Event</th>
                        <th class="px-3 py-2 font-medium">Actor</th>
                        <th class="px-3 py-2 font-medium">Subject</th>
                        <th class="px-3 py-2 font-medium">IP</th>
                        <th class="px-3 py-2 font-medium">Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="logs.data.length === 0">
                        <td
                            colspan="7"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            No audit entries match these filters.
                        </td>
                    </tr>
                    <tr
                        v-for="entry in logs.data"
                        :key="entry.id"
                        class="border-b align-top last:border-0"
                    >
                        <td class="px-3 py-2 text-muted-foreground">
                            {{ entry.id }}
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ formatDate(entry.created_at) }}
                        </td>
                        <td class="px-3 py-2">
                            <div class="font-mono text-xs">
                                {{ entry.event }}
                            </div>
                            <Badge
                                :variant="
                                    entry.category === 'phi_access'
                                        ? 'default'
                                        : 'outline'
                                "
                                class="mt-1"
                            >
                                {{
                                    categoryLabels
                                        .get(entry.category)
                                        ?.split(' (')[0] ?? entry.category
                                }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2">
                            <div>{{ entry.actor_email ?? '—' }}</div>
                            <div
                                v-if="entry.user_id"
                                class="text-xs text-muted-foreground"
                            >
                                user #{{ entry.user_id }}
                            </div>
                        </td>
                        <td class="px-3 py-2 text-xs">
                            <template v-if="entry.auditable_type">
                                {{ entry.auditable_type.split('\\').pop() }}
                                #{{ entry.auditable_id }}
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ entry.ip_address ?? '—' }}
                        </td>
                        <td class="px-3 py-2">
                            <pre
                                v-if="details(entry)"
                                class="max-w-md overflow-x-auto text-xs whitespace-pre-wrap"
                                >{{ details(entry) }}</pre>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="logs.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <span class="text-muted-foreground">
                Page {{ logs.current_page }} of {{ logs.last_page }}
            </span>
            <div class="flex gap-2">
                <Button
                    v-if="logs.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="logs.prev_page_url"
                        :only="reloadProps"
                        preserve-state
                        preserve-scroll
                    >
                        Previous
                    </Link>
                </Button>
                <Button
                    v-if="logs.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link
                        :href="logs.next_page_url"
                        :only="reloadProps"
                        preserve-state
                        preserve-scroll
                    >
                        Next
                    </Link>
                </Button>
            </div>
        </div>
    </div>
</template>
