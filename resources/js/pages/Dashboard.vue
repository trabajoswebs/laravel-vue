<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import PlaceholderPattern from '../components/PlaceholderPattern.vue';
import DrawerExample from '@/components/DrawerExample.vue';
import { Button } from '@/components/ui/button';
import { useLanguage } from '@/composables/useLanguage';

const { t } = useLanguage();

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: t('dashboard.title'),
        href: '/dashboard',
    },
];
</script>

<template>

    <Head :title="t('dashboard.title')" />
    <AppLayout :breadcrumbs="breadcrumbs">
        <DrawerExample side="left">
            <template #trigger>
                <Button>{{ t('dashboard.open') }}</Button>
            </template>
            <template #title>
                {{ t('dashboard.confirm_read') }}
            </template>
            <template #description>
                {{ t('dashboard.description') }}
            </template>
            <template #footer>
                <Button>{{ t('dashboard.send') }}</Button>
                <Button variant="outline">
                    {{ t('dashboard.close') }}
                </Button>
            </template>
        </DrawerExample>
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4 overflow-x-auto">
            <div class="grid auto-rows-min gap-4 md:grid-cols-3">
                <div
                    class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
                <div
                    class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
                <div
                    class="relative aspect-video overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border">
                    <PlaceholderPattern />
                </div>
            </div>
            <div
                class="relative min-h-[100vh] flex-1 rounded-xl border border-sidebar-border/70 md:min-h-min dark:border-sidebar-border">
                <PlaceholderPattern />
            </div>
        </div>
    </AppLayout>
</template>
