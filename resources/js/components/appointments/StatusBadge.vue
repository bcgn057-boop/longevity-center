<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { AppointmentStatus } from '@/types';

defineProps<{
    status: AppointmentStatus;
    label: string;
}>();

const variants = {
    scheduled: 'default',
    completed: 'secondary',
    cancelled: 'outline',
} as const;
</script>

<template>
    <Badge :variant="variants[status]">{{ label }}</Badge>
</template>
