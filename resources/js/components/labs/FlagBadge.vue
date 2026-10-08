<script setup lang="ts">
defineProps<{
    flag: 'low' | 'normal' | 'high' | null;
    label: string | null;
}>();
</script>

<template>
    <span
        v-if="flag"
        class="inline-flex rounded-full px-2 py-0.5 text-xs"
        :class="{
            'bg-muted text-muted-foreground': flag === 'normal',
            'bg-amber-100 text-amber-900 dark:bg-amber-500/20 dark:text-amber-200':
                flag !== 'normal',
        }"
        :title="`${label} (against the lab's printed range)`"
    >
        {{ label }}
    </span>
    <span v-else class="text-xs text-muted-foreground">—</span>
</template>
