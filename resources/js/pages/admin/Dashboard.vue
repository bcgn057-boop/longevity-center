<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { dashboard } from '@/routes/admin';
import { analytics } from '@/routes/compliance';
import { index as auditLogs } from '@/routes/compliance/audit-logs';

const page = usePage();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Administration',
                href: dashboard(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Administration" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <h1 class="text-xl font-semibold">Administration</h1>
        <p class="text-sm text-muted-foreground">
            Only Super Admins and Clinic Admins can see this page.
        </p>
        <Link
            :href="analytics()"
            class="text-sm font-medium underline underline-offset-4"
        >
            Practice analytics
        </Link>
        <Link
            v-if="page.props.auth.role === 'super_admin'"
            :href="auditLogs()"
            class="text-sm font-medium underline underline-offset-4"
        >
            View the audit log
        </Link>
    </div>
</template>
