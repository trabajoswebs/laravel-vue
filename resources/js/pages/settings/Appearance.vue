<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import AppearanceTabs from '@/components/AppearanceTabs.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { useLanguage } from '@/composables/useLanguage';

import FormCard from '@/components/FormCard.vue';

const { t } = useLanguage();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: t('settings.appearance_settings'),
        href: '/settings/appearance',
    },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">

        <Head :title="t('settings.appearance_settings')" />

        <SettingsLayout>
            <div class="space-y-6">
                <FormCard>
                    <HeadingSmall :title="t('settings.appearance_settings')"
                        :description="t('settings.appearance_description')" />
                    <AppearanceTabs />
                </FormCard>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
