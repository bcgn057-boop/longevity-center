<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserForm from '@/components/admin/UserForm.vue';
import { dashboard } from '@/routes/admin';
import { index, update } from '@/routes/admin/users';
import type { LabPartnerOption, ManagedUser, RoleOption } from '@/types';

defineProps<{
    account: ManagedUser;
    roles: RoleOption[];
    labPartners: LabPartnerOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Administration', href: dashboard() },
            { title: 'Users', href: index() },
        ],
    },
});
</script>

<template>
    <Head :title="`Edit ${account.name}`" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <h1 class="text-xl font-semibold">Edit {{ account.name }}</h1>
        <UserForm
            :account="account"
            :roles="roles"
            :lab-partners="labPartners"
            :action="{ url: update(account.id).url, method: 'put' }"
            submit-label="Save changes"
        />
    </div>
</template>
