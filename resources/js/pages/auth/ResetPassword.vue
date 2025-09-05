<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';
import { useLanguage } from '@/composables/useLanguage';

const { t } = useLanguage();

const props = defineProps<{
    token: string;
    email: string;
}>();

const inputEmail = ref(props.email);
</script>

<template>
    <AuthLayout :title="t('auth.reset_password_title')" :description="t('auth.reset_password_description')">

        <Head :title="t('auth.reset_password')" />

        <Form method="post" :action="route('password.store')" :transform="(data) => ({ ...data, token, email })"
            :reset-on-success="['password', 'password_confirmation']" v-slot="{ errors, processing }">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.email') }}</Label>
                    <Input id="email" type="email" name="email" autocomplete="email" v-model="inputEmail"
                        class="block w-full mt-1" readonly />
                    <InputError :message="errors.email" class="mt-2" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">{{ t('auth.password') }}</Label>
                    <Input id="password" type="password" name="password" autocomplete="new-password"
                        class="block w-full mt-1" autofocus :placeholder="t('auth.password_placeholder')" />
                    <InputError :message="errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">{{ t('auth.confirm_password') }}</Label>
                    <Input id="password_confirmation" type="password" name="password_confirmation"
                        autocomplete="new-password" class="block w-full mt-1"
                        :placeholder="t('auth.confirm_password_placeholder')" />
                    <InputError :message="errors.password_confirmation" />
                </div>

                <Button type="submit" class="w-full mt-4" :disabled="processing">
                    <LoaderCircle v-if="processing" class="w-4 h-4 animate-spin" />
                    {{ t('auth.reset_password_button') }}
                </Button>
            </div>
        </Form>
    </AuthLayout>
</template>
