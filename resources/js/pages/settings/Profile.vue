<script setup lang="ts">
import { computed } from 'vue';
import { Form, Head, Link, usePage } from '@inertiajs/vue3';

import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type User } from '@/types';
import { useLanguage } from '@/composables/useLanguage';
import HeadingSmall from '@/components/HeadingSmall.vue';
import AvatarUploader from '@/components/settings/AvatarUploader.vue';
import FormCard from '@/components/FormCard.vue';
import InlineStatus from '@/components/ui/InlineStatus.vue';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    avatarRoutes: {
        upload: string;
        delete: string;
    };
}

const props = defineProps<Props>();

const { t } = useLanguage();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: t('profile.title'),
        href: '/settings/profile',
    },
];

const page = usePage();
const user = computed<User>(() => page.props.auth.user as User);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">

        <Head :title="t('profile.title')" />

        <SettingsLayout>
            <div class="flex flex-col space-y-8">
                <!-- Actualizar Avatar -->
                <FormCard>
                    <HeadingSmall :title="t('profile.avatar_title')" :description="t('profile.avatar_description')" />
                    <AvatarUploader
                        :user="user"
                        :upload-route="props.avatarRoutes.upload"
                        :delete-route="props.avatarRoutes.delete"
                    />
                </FormCard>

                <!-- Actualizar Cuenta -->
                <FormCard>
                    <HeadingSmall :title="t('profile.personal_info')" :description="t('profile.update_profile')" />
                    <Form method="patch" :action="route('profile.update')" class="space-y-8"
                        v-slot="{ errors, processing, recentlySuccessful }"
                        :options="{ preserveScroll: true }">
                        <div class="grid gap-1">
                            <Label for="name">{{ t('profile.name') }}</Label>
                            <Input id="name" class="mt-1 block w-full" name="name" :default-value="user.name" required
                                autocomplete="name" :placeholder="t('profile.name')" />
                            <InputError class="mt-2" :message="errors.name" />
                        </div>

                        <div class="grid gap-1">
                            <Label for="email">{{ t('profile.email') }}</Label>
                            <Input id="email" type="email" class="mt-1 block w-full" name="email"
                                :default-value="user.email" required autocomplete="username"
                                :placeholder="t('profile.email')" />
                            <InputError class="mt-2" :message="errors.email" />
                        </div>

                        <div v-if="mustVerifyEmail && !user.email_verified_at">
                            <p class="-mt-4 text-sm text-muted-foreground">
                                {{ t('profile.email_not_verified') }}.
                                <Link :href="route('verification.send')" method="post" as="button"
                                    class="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary/60 focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card">
                                {{ t('profile.verify_email') }}.
                                </Link>
                            </p>

                            <div v-if="status === 'verification-link-sent'"
                                class="mt-2 text-sm font-medium text-green-600">
                                {{ t('profile.email_verification_sent') }}.
                            </div>
                        </div>

                        <div class="flex items-center gap-4 mt-4">
                            <Button :disabled="processing" variant="default">
                                {{ t('common.save') }}
                            </Button>

                            <InlineStatus class="flex items-center" dense :show="recentlySuccessful"
                                :message="t('profile.profile_updated')" variant="success" />
                        </div>
                    </Form>
                </FormCard>

                <!-- Eliminar Cuenta -->
                <FormCard>
                    <DeleteUser />
                </FormCard>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
