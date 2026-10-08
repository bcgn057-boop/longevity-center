<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import { index } from '@/routes/notifications';

const page = usePage();

const unread = computed(() => page.props.unreadNotifications ?? 0);
const label = computed(() =>
    unread.value > 0
        ? `Notifications (${unread.value} unread)`
        : 'Notifications',
);
</script>

<template>
    <Link
        :href="index()"
        class="relative inline-flex size-9 items-center justify-center rounded-md hover:bg-muted"
        :aria-label="label"
        :title="label"
    >
        <Bell class="size-5" />
        <span
            v-if="unread > 0"
            class="absolute top-1 right-1 flex min-w-4 items-center justify-center rounded-full bg-destructive px-1 text-[10px] leading-4 font-medium text-white"
        >
            {{ unread > 99 ? '99+' : unread }}
        </span>
    </Link>
</template>
