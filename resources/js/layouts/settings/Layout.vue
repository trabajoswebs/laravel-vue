<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { useLanguage } from '@/composables/useLanguage';

const { t } = useLanguage();

const sidebarNavItems: NavItem[] = [
    {
        title: t('navigation.profile'),
        href: '/settings/profile',
    },
    {
        title: t('settings.password_settings'),
        href: '/settings/password',
    },
    {
        title: t('settings.appearance'),
        href: '/settings/appearance',
    },
];

const page = usePage();

const currentPath = page.props.ziggy?.location ? new URL(page.props.ziggy.location).pathname : '';
</script>

<template>
    <div class="w-full max-w-5xl px-4 py-6 md:px-6 lg:py-10 xl:max-w-6xl">
        <Heading :title="t('settings.title')" :description="t('settings.appearance_description')" />

        <div class="mt-6 flex flex-col gap-8 lg:flex-row lg:items-start">
            <!-- SIDEBAR -->
            <aside class="w-full max-w-full lg:w-64 lg:flex-none">
                <nav class="flex flex-col space-y-1">
                    <Button v-for="item in sidebarNavItems" :key="item.href" variant="ghost" :class="[
                        'w-full justify-start',
                        { 'bg-muted': currentPath === item.href }
                    ]" as-child>
                        <Link :href="item.href">
                        {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <!-- Separador solo en móvil -->
            <Separator class="my-6 lg:hidden" />

            <!-- CONTENIDO -->
            <div class="flex-1 md:max-w-3xl lg:max-w-3xl xl:max-w-4xl">
                <section class="space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
