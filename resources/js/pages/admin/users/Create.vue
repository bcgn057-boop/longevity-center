<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserForm from '@/components/admin/UserForm.vue';
import { dashboard } from '@/routes/admin';
import { index, store } from '@/routes/admin/users';
import type { LabPartnerOption, RoleOption } from '@/types';

defineProps<{
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
    <Head title="New account" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div>
            <h1 class="text-xl font-semibold">New account</h1>
            <p class="text-sm text-muted-foreground">
                To give a patient portal access, create a Patient account here,
                then link it from the patient's record.
            </p>
        </div>
        <UserForm
            :roles="roles"
            :lab-partners="labPartners"
            :action="{ url: store().url, method: 'post' }"
            submit-label="Create account and send invitation"
        />
    </div>
</template>
