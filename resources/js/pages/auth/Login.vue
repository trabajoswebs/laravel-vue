<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Form, Head } from '@inertiajs/vue3';
import { LoaderCircle, Eye, EyeOff } from 'lucide-vue-next';
import { ref } from 'vue';
import { useLanguage } from '@/composables/useLanguage';

const { t } = useLanguage();

const showPassword = ref(false);

defineProps<{
    status?: string;
    canResetPassword: boolean;
}>();
</script>

<template>
    <AuthBase :title="t('auth.login_title')" :description="t('auth.login_description')">

        <Head :title="t('auth.login')" />

        <div v-if="status" class="mb-4 text-sm font-medium text-center text-green-600">
            {{ status }}
        </div>

        <Form method="post" :action="route('login')" :reset-on-success="['password']" v-slot="{ errors, processing }"
            class="flex flex-col gap-6">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">{{ t('auth.email_address') }}</Label>
                    <Input id="email" type="email" name="email" required autofocus :tabindex="1" autocomplete="email"
                        :placeholder="t('auth.email_placeholder')" />
                    <InputError :message="errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">{{ t('auth.password') }}</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm"
                            :tabindex="5">
                            {{ t('auth.forgot_password_link') }}
                        </TextLink>
                    </div>
                    <div class="relative">
                        <Input id="password" :type="showPassword ? 'text' : 'password'" name="password" required
                            :tabindex="2" autocomplete="current-password" class="pr-10"
                            :placeholder="t('auth.password_placeholder')" />
                        <button type="button"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-neutral-500 transition hover:text-neutral-700 cursor-pointer"
                            @click="showPassword = !showPassword" :aria-label="showPassword
                                ? t('settings.hide_password')
                                : t('settings.show_password')"
                            :title="showPassword ? t('settings.hide_password') : t('settings.show_password')">
                            <component :is="showPassword ? EyeOff : Eye" class="h-4 w-4" />
                        </button>
                    </div>
                    <InputError :message="errors.password" />
                </div>

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3">
                        <Checkbox id="remember" name="remember" :tabindex="3" />
                        <span>{{ t('auth.remember_me') }}</span>
                    </Label>
                </div>

                <Button type="submit" class="w-full mt-4" :tabindex="4" :disabled="processing">
                    <LoaderCircle v-if="processing" class="w-4 h-4 animate-spin" />
                    {{ t('auth.log_in') }}
                </Button>
            </div>

            <div class="text-sm text-center text-muted-foreground">
                {{ t('auth.dont_have_account') }}
                <TextLink :href="route('register')" :tabindex="5">{{ t('auth.sign_up') }}</TextLink>
            </div>
        </Form>
    </AuthBase>
</template>
