<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { computed, ref } from 'vue';
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
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { dashboard } from '@/routes/admin';
import {
    create,
    deactivate,
    edit,
    index,
    invite,
    reactivate,
} from '@/routes/admin/users';
import type { ManagedUser, RoleOption } from '@/types';

type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

const props = defineProps<{
    users: Paginated<ManagedUser>;
    filters: { search: string; role: string; status: string };
    roles: RoleOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administration', href: dashboard() },
            { title: 'Users', href: index() },
        ],
    },
});

const selectClass =
    'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30';

const filters = ref({ ...props.filters });

function apply(): void {
    router.get(
        index().url,
        Object.fromEntries(
            Object.entries(filters.value).filter(([, value]) => value !== ''),
        ),
        { preserveState: true, preserveScroll: true },
    );
}

const confirming = ref<ManagedUser | null>(null);
const confirmOpen = ref(false);
const action = useForm({});

function askDeactivate(user: ManagedUser): void {
    confirming.value = user;
    action.clearErrors();
    confirmOpen.value = true;
}

function submitDeactivate(): void {
    if (!confirming.value) {
        return;
    }

    action.post(deactivate(confirming.value.id).url, {
        preserveScroll: true,
        onSuccess: () => {
            confirmOpen.value = false;
        },
    });
}

// e.g. "This is the only active Super Admin."
const actionErrors = computed(() =>
    Object.values(action.errors as Record<string, string>),
);

function post(url: string): void {
    action.post(url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Users" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h1 class="text-xl font-semibold">Users</h1>
                <p class="text-sm text-muted-foreground">
                    {{ users.total }} accounts. Deactivated accounts cannot sign
                    in; their history is kept.
                </p>
            </div>
            <Button as-child>
                <Link :href="create()"
                    ><Plus class="size-4" /> New account</Link
                >
            </Button>
        </div>

        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <Input
                v-model="filters.search"
                placeholder="Name or email"
                aria-label="Search by name or email"
                class="w-56"
            />
            <select
                v-model="filters.role"
                aria-label="Role"
                :class="selectClass"
            >
                <option value="">All roles</option>
                <option
                    v-for="role in roles"
                    :key="role.value"
                    :value="role.value"
                >
                    {{ role.label }}
                </option>
            </select>
            <select
                v-model="filters.status"
                aria-label="Status"
                :class="selectClass"
            >
                <option value="">Any status</option>
                <option value="active">Active</option>
                <option value="deactivated">Deactivated</option>
            </select>
            <Button type="submit" variant="outline">Filter</Button>
        </form>

        <div class="overflow-x-auto rounded-lg border">
            <table class="w-full min-w-[48rem] text-left text-sm">
                <thead class="border-b bg-muted/50">
                    <tr>
                        <th class="px-3 py-2 font-medium">Name</th>
                        <th class="px-3 py-2 font-medium">Role</th>
                        <th class="px-3 py-2 font-medium">Lab partner</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="users.data.length === 0">
                        <td
                            colspan="5"
                            class="px-3 py-6 text-center text-muted-foreground"
                        >
                            No accounts match.
                        </td>
                    </tr>
                    <tr
                        v-for="user in users.data"
                        :key="user.id"
                        class="border-b last:border-0"
                        :class="{ 'text-muted-foreground': user.deactivated }"
                    >
                        <td class="px-3 py-2">
                            <div class="font-medium text-foreground">
                                {{ user.name }}
                                <span
                                    v-if="user.is_you"
                                    class="font-normal text-muted-foreground"
                                    >(you)</span
                                >
                            </div>
                            <div class="text-xs">{{ user.email }}</div>
                        </td>
                        <td class="px-3 py-2">{{ user.role_label }}</td>
                        <td class="px-3 py-2">{{ user.lab_partner ?? '—' }}</td>
                        <td class="px-3 py-2">
                            <Badge v-if="user.deactivated" variant="outline">
                                Deactivated
                            </Badge>
                            <Badge
                                v-else-if="!user.verified"
                                variant="secondary"
                            >
                                Invited
                            </Badge>
                            <Badge v-else>Active</Badge>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <template v-if="user.can.update">
                                <Button variant="ghost" size="sm" as-child>
                                    <Link :href="edit(user.id)">Edit</Link>
                                </Button>
                                <Button
                                    v-if="!user.verified && !user.deactivated"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="action.processing"
                                    @click="post(invite(user.id).url)"
                                >
                                    Resend invitation
                                </Button>
                                <Button
                                    v-if="user.deactivated"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="action.processing"
                                    @click="post(reactivate(user.id).url)"
                                >
                                    Reactivate
                                </Button>
                            </template>
                            <Button
                                v-if="user.can.deactivate && !user.deactivated"
                                variant="ghost"
                                size="sm"
                                @click="askDeactivate(user)"
                            >
                                Deactivate
                            </Button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="users.last_page > 1"
            class="flex items-center justify-between text-sm"
        >
            <span class="text-muted-foreground">
                Page {{ users.current_page }} of {{ users.last_page }}
            </span>
            <div class="flex gap-2">
                <Button
                    v-if="users.prev_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="users.prev_page_url" preserve-scroll>
                        Previous
                    </Link>
                </Button>
                <Button
                    v-if="users.next_page_url"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <Link :href="users.next_page_url" preserve-scroll>
                        Next
                    </Link>
                </Button>
            </div>
        </div>

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle
                        >Deactivate {{ confirming?.name }}?</DialogTitle
                    >
                    <DialogDescription>
                        They are signed out everywhere at once and cannot sign
                        in again until reactivated. Their records and audit
                        history are kept.
                    </DialogDescription>
                </DialogHeader>
                <p
                    v-for="message in actionErrors"
                    :key="message"
                    class="text-sm text-destructive"
                >
                    {{ message }}
                </p>
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        :disabled="action.processing"
                        @click="submitDeactivate"
                    >
                        Deactivate
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
