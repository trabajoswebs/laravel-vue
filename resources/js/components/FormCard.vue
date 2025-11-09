<script setup lang="ts">
import { cn } from '@/lib/utils'

const props = defineProps<{ class?: string }>()
</script>

<template>
    <div :class="cn(
        'bg-white shadow rounded-lg p-6 bg-gradient-to-br from-card to-muted/30 lg:p-8 transition-all duration-300 space-y-8',
        props.class
    )">
        <slot />
    </div>
</template>
