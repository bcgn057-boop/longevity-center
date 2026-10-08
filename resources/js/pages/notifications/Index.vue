<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { index, read, readAll } from '@/routes/notifications';
import type { AppNotification, Paginated } from '@/types';

const props = defineProps<{
    notifications: Paginated<AppNotification>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Notifications', href: index() }],
    },
});

function open(notification: AppNotification): void {
    const follow = (): void => {
        if (notification.url) {
            router.visit(notification.url);
        }
    };

    if (notification.read) {
        follow();

        return;
    }

    router.post(
        read(notification.id).url,
        {},
        { preserveScroll: true, onSuccess: follow },
    );
}

function markAllRead(): void {
    router.post(readAll().url, {}, { preserveScroll: true });
}

const hasUnread = (): boolean =>
    props.notifications.data.some((notification) => !notification.read);
</script>

<template>
    <Head title="Notifications" />

    <div class="flex h-full max-w-3xl flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <h1 class="text-xl font-semibold">Notifications</h1>
            <Button
                v-if="hasUnread()"
                variant="outline"
                size="sm"
                @click="markAllRead"
            >
                Mark all as read
            </Button>
        </div>

        <p
            v-if="notifications.data.length === 0"
            class="text-sm text-muted-foreground"
        >
            You have no notifications.
        </p>

        <ul v-else class="divide-y rounded-lg border">
            <li
                v-for="notification in notifications.data"
                :key="notification.id"
            >
                <button
                    type="button"
                    class="flex w-full gap-3 px-4 py-3 text-left hover:bg-muted/40"
                    @click="open(notification)"
                >
                    <span
                        class="mt-1.5 size-2 shrink-0 rounded-full"
                        :class="
                            notification.read ? 'bg-transparent' : 'bg-primary'
                        "
                        :aria-label="notification.read ? 'Read' : 'Unread'"
                    />
                    <span class="flex-1 space-y-1">
                        <span
                            class="block text-sm"
                            :class="{ 'font-medium': !notification.read }"
                        >
                            {{ notification.title }}
                        </span>
                        <span
                            v-if="notification.body"
                            class="block text-sm whitespace-pre-line text-muted-foreground"
                        >
                            {{ notification.body }}
                        </span>
                        <span class="block text-xs text-muted-foreground">
                            {{ notification.created_at }}
                        </span>
                    </span>
                </button>
            </li>
        </ul>

        <div v-if="notifications.last_page > 1" class="flex justify-end gap-2">
            <Button
                v-if="notifications.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="notifications.prev_page_url">Newer</Link>
            </Button>
            <Button
                v-if="notifications.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="notifications.next_page_url">Older</Link>
            </Button>
        </div>
    </div>
</template>
