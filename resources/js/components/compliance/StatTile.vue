<script setup lang="ts">
defineProps<{
    label: string;
    value: string | number;
    detail?: string;
}>();
</script>

<template>
    <div class="rounded-lg border p-4">
        <div class="text-sm text-muted-foreground">{{ label }}</div>
        <div class="mt-1 text-2xl font-semibold tabular-nums">{{ value }}</div>
        <div v-if="detail" class="mt-1 text-xs text-muted-foreground">
            {{ detail }}
        </div>
    </div>
</template>
