<script setup lang="ts">
import { cn } from '@/lib/utils'

const props = defineProps<{ class?: string }>()
</script>

<template>
    <section :class="cn(
        // base: respeta tema claro/oscuro y tokens de diseño
        'w-full rounded-xl border border-border bg-card text-card-foreground',
        // espaciados responsivos
        'p-4 sm:p-6 lg:p-8',
        // sombra discreta y animación coherente
        'shadow-sm lg:shadow-md transition-colors duration-200',
        // separación interna vertical estándar
        'space-y-6 sm:space-y-8',
        props.class,
    )">
        <slot />
    </section>
</template>
