<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { useLanguage } from '@/composables/useLanguage';

const { t } = useLanguage();

defineProps<{
    status?: string;
}>();
</script>

<template>
    <AuthLayout :title="t('auth.forgot_password_title')" :description="t('auth.forgot_password_description')">

        <Head :title="t('auth.forgot_password')" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ status }}
        </div>

        <div class="space-y-6">
            <Form method="post" :action="route('password.email')" v-slot="{ errors, processing }">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.email_address') }}</Label>
                    <Input id="email" type="email" name="email" autocomplete="off" autofocus
                        :placeholder="t('auth.email_placeholder')" />
                    <InputError :message="errors.email" />
                </div>

                <div class="my-6 flex items-center justify-start">
                    <Button class="w-full" :disabled="processing">
                        <LoaderCircle v-if="processing" class="h-4 w-4 animate-spin" />
                        {{ t('auth.email_password_reset_link') }}
                    </Button>
                </div>
            </Form>

            <div class="space-x-1 text-center text-sm text-muted-foreground">
                <span>{{ t('auth.or_return_to') }}</span>
                <TextLink :href="route('login')">{{ t('auth.log_in') }}</TextLink>
            </div>
        </div>
    </AuthLayout>
</template>
