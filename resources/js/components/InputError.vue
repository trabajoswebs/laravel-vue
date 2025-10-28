<script setup lang="ts">
defineProps<{
    message?: string;
    id?: string;
}>();
</script>

<template>
    <div v-show="message">
        <p
            :id="id"
            class="text-sm text-red-600 dark:text-red-500"
            role="alert"
            aria-live="assertive"
        >
            {{ message }}
        </p>
    </div>
</template>
