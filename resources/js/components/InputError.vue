<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        message?: string;
        id?: string;
        dense?: boolean; // reduce margen superior cuando se usa pegado al input
    }>(),
    {
        message: '',
        dense: false,
    }
);
</script>

<template>
    <Transition enter-active-class="transition ease-in-out" enter-from-class="opacity-0"
        leave-active-class="transition ease-in-out" leave-to-class="opacity-0">
        <p v-show="message" :id="id"
            :class="[
                'flex items-center gap-2 text-sm font-medium text-red-600 dark:text-red-400',
                dense ? 'mt-0' : 'mt-1'
            ]"
            role="alert"
            aria-live="assertive">
            <span class="inline-flex h-2 w-2 rounded-full bg-red-500" aria-hidden="true"></span>
            {{ message }}
        </p>
    </Transition>
</template>
