<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
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
    <AuthLayout :title="t('auth.verify_email_title')" :description="t('auth.verify_email_description')">

        <Head :title="t('auth.email_verification')" />

        <div v-if="status === 'verification-link-sent'" class="mb-4 text-center text-sm font-medium text-green-600">
            {{ t('auth.verification_link_sent') }}
        </div>

        <Form method="post" :action="route('verification.send')" class="space-y-6 text-center" v-slot="{ processing }">
            <Button :disabled="processing" variant="secondary">
                <LoaderCircle v-if="processing" class="h-4 w-4 animate-spin" />
                {{ t('auth.resend_verification_email') }}
            </Button>

            <TextLink :href="route('logout')" method="post" as="button" class="mx-auto block text-sm">{{
                t('auth.logout') }}</TextLink>
        </Form>
    </AuthLayout>
</template>
