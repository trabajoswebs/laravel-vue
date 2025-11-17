<script setup lang="ts">
interface Props {
    title: string;
    description?: string;
}

defineProps<Props>();
</script>

<template>
    <header class="space-y-1 mb-4">
        <h3 class="text-2xl font-bold">{{ title }}</h3>
        <p v-if="description" class="text-sm leading-relaxed text-foreground/70">
            {{ description }}
        </p>
    </header>
</template>
