<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        show?: boolean;
        message?: string | null;
        variant?: 'success' | 'error' | 'warning' | 'info';
        dense?: boolean; // elimina el margen superior para uso inline junto a botones
    }>(),
    {
        show: false,
        message: '',
        variant: 'success',
        dense: false,
    }
);

const palette: Record<typeof props.variant, { text: string; dot: string }> = {
    success: { text: 'text-emerald-600 dark:text-emerald-400', dot: 'bg-emerald-500' },
    error: { text: 'text-red-600 dark:text-red-400', dot: 'bg-red-500' },
    warning: { text: 'text-amber-600 dark:text-amber-400', dot: 'bg-amber-500' },
    info: { text: 'text-blue-600 dark:text-blue-400', dot: 'bg-blue-500' },
};

const current = computed(() => palette[props.variant] ?? palette.success);
</script>

<template>
    <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0"
        leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
        <p v-show="show && message"
            :class="[
                current.text,
                'text-sm font-medium flex items-center gap-2',
                props.dense ? 'mt-0' : 'mt-3'
            ]">
            <span class="inline-flex h-2 w-2 rounded-full" :class="current.dot" aria-hidden="true"></span>
            {{ message }}
        </p>
    </Transition>
</template>
