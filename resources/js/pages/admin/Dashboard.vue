<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { dashboard } from '@/routes/admin';

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
    </div>
</template>
