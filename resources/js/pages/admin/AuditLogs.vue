<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dashboard } from '@/routes/admin';
import { index } from '@/routes/admin/audit-logs';

type AuditLogEntry = {
    id: number;
    event: string;
    user_id: number | null;
    actor_email: string | null;
    auditable_type: string | null;
    auditable_id: number | null;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
    ip_address: string | null;
    user_agent: string | null;
    created_at: string;
};

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
    filters: { event: string | null; user_id: number | null };
    events: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administration', href: dashboard() },
            { title: 'Audit Log', href: index() },
        ],
    },
});

const event = ref(props.filters.event ?? '');
const userId = ref(props.filters.user_id?.toString() ?? '');

// Filtering and paging are partial reloads: only these props are refetched,
// and the server does not record them as new views of the audit log.
const reloadProps = ['logs', 'filters'];

function reload(query: Record<string, string>): void {
    router.get(index().url, query, {
        only: reloadProps,
        preserveState: true,
        preserveScroll: true,
    });
}

function applyFilters(): void {
    const query: Record<string, string> = {};
    const user = String(userId.value ?? '').trim();

    if (event.value) {
        query.event = event.value;
    }

    if (user !== '') {
        query.user_id = user;
    }

    reload(query);
}

function resetFilters(): void {
    event.value = '';
    userId.value = '';
    reload({});
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
        <div>
            <h1 class="text-xl font-semibold">Audit Log</h1>
            <p class="text-sm text-muted-foreground">
                Append-only record of security events. Entries cannot be edited
                or deleted. {{ logs.total }} matching entries.
            </p>
        </div>

        <!-- action/method/name make the form work as a plain GET even if the
             Inertia handler is bypassed; normally it is a partial reload. -->
        <form
            :action="index().url"
            method="get"
            class="flex flex-wrap items-end gap-3"
            @submit.prevent="applyFilters"
        >
            <div class="grid gap-1.5">
                <Label for="event">Event</Label>
                <select
                    id="event"
                    v-model="event"
                    name="event"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                >
                    <option value="">All events</option>
                    <option v-for="name in events" :key="name" :value="name">
                        {{ name }}
                    </option>
                </select>
            </div>

            <div class="grid gap-1.5">
                <Label for="user_id">User ID</Label>
                <Input
                    id="user_id"
                    v-model="userId"
                    name="user_id"
                    type="number"
                    min="1"
                    class="w-32"
                />
            </div>

            <Button type="submit">Filter</Button>
            <Button type="button" variant="outline" @click="resetFilters">
                Reset
            </Button>
        </form>

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
                        <td class="px-3 py-2 font-mono text-xs">
                            {{ entry.event }}
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
