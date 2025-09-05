<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppHeader from '@/components/AppHeader.vue';
import AppShell from '@/components/AppShell.vue';
import RootLayout from '@/layouts/RootLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <RootLayout>
        <AppShell class="flex-col">
            <AppHeader :breadcrumbs="breadcrumbs" />
            <AppContent>
                <slot />
            </AppContent>
        </AppShell>
    </RootLayout>
</template>
