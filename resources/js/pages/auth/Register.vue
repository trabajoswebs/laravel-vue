<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useLanguage } from '@/composables/useLanguage';

const { t } = useLanguage();
</script>

<template>
    <AuthBase :title="t('auth.register_title')" :description="t('auth.register_description')">

        <Head :title="t('auth.register')" />

        <Form method="post" :action="route('register')" :reset-on-success="['password', 'password_confirmation']"
            v-slot="{ errors, processing }" class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="name">{{ t('auth.name') }}</Label>
                    <Input id="name" type="text" required autofocus :tabindex="1" autocomplete="name" name="name"
                        :placeholder="t('auth.name_placeholder')" />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.email_address') }}</Label>
                    <Input id="email" type="email" required :tabindex="2" autocomplete="email" name="email"
                        :placeholder="t('auth.email_placeholder')" />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{ t('auth.password') }}</Label>
                    <Input id="password" type="password" required :tabindex="3" autocomplete="new-password"
                        name="password" :placeholder="t('auth.password_placeholder')" />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{ t('auth.confirm_password') }}</Label>
                    <Input id="password_confirmation" type="password" required :tabindex="4" autocomplete="new-password"
                        name="password_confirmation" :placeholder="t('auth.confirm_password_placeholder')" />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button type="submit" class="w-full mt-2" tabindex="5" :disabled="processing">
                    <LoaderCircle v-if="processing" class="w-4 h-4 animate-spin" />
                    {{ t('auth.create_account') }}
                </Button>
            </div>

            <div class="text-sm text-center text-muted-foreground">
                {{ t('auth.already_have_account') }}
                <TextLink :href="route('login')" class="underline underline-offset-4" :tabindex="6">{{ t('auth.sign_in')
                    }}</TextLink>
            </div>
        </Form>
    </AuthBase>
</template>
