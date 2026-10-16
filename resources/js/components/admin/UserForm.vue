<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { LabPartnerOption, ManagedUser, RoleOption } from '@/types';

const props = defineProps<{
    account?: ManagedUser;
    roles: RoleOption[];
    labPartners: LabPartnerOption[];
    action: { url: string; method: 'post' | 'put' };
    submitLabel: string;
}>();

const selectClass =
    'h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30';

const form = useForm({
    name: props.account?.name ?? '',
    ...(props.account ? {} : { email: '' }),
    role: props.account?.role ?? '',
    lab_partner_id: props.account?.lab_partner_id?.toString() ?? '',
});

const isLab = computed(() => form.role === 'lab_staff');
const partner = computed(() =>
    props.labPartners.find((p) => p.id.toString() === form.lab_partner_id),
);
const changingOwnRole = computed(() => props.account?.is_you ?? false);

function submit(): void {
    form.transform((data) => ({
        ...data,
        lab_partner_id: isLab.value ? data.lab_partner_id || null : null,
    })).submit(props.action.method, props.action.url, { preserveScroll: true });
}
</script>

<template>
    <form class="max-w-xl space-y-6" @submit.prevent="submit">
        <div class="grid gap-2">
            <Label for="name">Name</Label>
            <Input id="name" v-model="form.name" required maxlength="255" />
            <InputError :message="form.errors.name" />
        </div>

        <div v-if="!account" class="grid gap-2">
            <Label for="email">Email</Label>
            <Input
                id="email"
                v-model="form.email"
                type="email"
                required
                maxlength="255"
                autocomplete="off"
            />
            <p class="text-xs text-muted-foreground">
                We'll email a link to set a password. You never see or set it.
            </p>
            <InputError :message="form.errors.email" />
        </div>
        <div v-else class="grid gap-1 text-sm">
            <span class="text-muted-foreground">Email</span>
            <span>{{ account.email }}</span>
        </div>

        <div class="grid gap-2">
            <Label for="role">Role</Label>
            <select
                id="role"
                v-model="form.role"
                required
                :disabled="changingOwnRole"
                :class="selectClass"
            >
                <option value="" disabled>Choose a role…</option>
                <option
                    v-for="role in roles"
                    :key="role.value"
                    :value="role.value"
                    :disabled="!role.assignable"
                >
                    {{ role.label
                    }}{{ role.assignable ? '' : ' (Super Admin only)' }}
                </option>
            </select>
            <p v-if="changingOwnRole" class="text-xs text-muted-foreground">
                You cannot change your own role.
            </p>
            <InputError :message="form.errors.role" />
        </div>

        <div v-if="isLab" class="grid gap-2">
            <Label for="lab_partner_id">Lab partner</Label>
            <select
                id="lab_partner_id"
                v-model="form.lab_partner_id"
                required
                :class="selectClass"
            >
                <option value="" disabled>Choose the lab they work for…</option>
                <option
                    v-for="option in labPartners"
                    :key="option.id"
                    :value="option.id.toString()"
                >
                    {{ option.name }}
                </option>
            </select>
            <p class="text-xs text-muted-foreground">
                <template v-if="partner">
                    {{ partner.tests.map((test) => test.label).join(', ') }}.
                </template>
                Lab users only see requisitions and reports for their own lab.
            </p>
            <InputError :message="form.errors.lab_partner_id" />
        </div>

        <Button type="submit" :disabled="form.processing">
            {{ submitLabel }}
        </Button>
    </form>
</template>
