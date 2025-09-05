<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { ref } from 'vue';

// Components
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLanguage } from '../composables/useLanguage';

const { t } = useLanguage();

const passwordInput = ref<HTMLInputElement | null>(null);
</script>

<template>
    <div class="space-y-6">
        <HeadingSmall :title="t('delete_account.title')" :description="t('delete_account.description')" />
        <div class="p-4 space-y-4 border border-red-100 rounded-lg bg-red-50 dark:border-red-200/10 dark:bg-red-700/10">
            <div class="relative space-y-0.5 text-red-600 dark:text-red-100">
                <p class="font-medium">{{ t('delete_account.warning') }}</p>
                <p class="text-sm">{{ t('delete_account.warning_description') }}</p>
            </div>
            <Dialog>
                <DialogTrigger as-child>
                    <Button variant="destructive">{{ t('delete_account.delete_button') }}</Button>
                </DialogTrigger>
                <DialogContent>
                    <Form method="delete" :action="route('profile.destroy')" reset-on-success
                        @error="() => passwordInput?.focus()" :options="{
                            preserveScroll: true,
                        }" class="space-y-6" v-slot="{ errors, processing, reset, clearErrors }">
                        <DialogHeader class="space-y-3">
                            <DialogTitle>{{ t('delete_account.confirm_title') }}</DialogTitle>
                            <DialogDescription>
                                {{ t('delete_account.confirm_description') }}
                            </DialogDescription>
                        </DialogHeader>

                        <div class="grid gap-2">
                            <Label for="password" class="sr-only">{{ t('delete_account.password_label') }}</Label>
                            <Input id="password" type="password" name="password" ref="passwordInput"
                                :placeholder="t('delete_account.password_placeholder')" />
                            <InputError :message="errors.password" />
                        </div>

                        <DialogFooter class="gap-2">
                            <DialogClose as-child>
                                <Button variant="secondary" @click="
                                    () => {
                                        clearErrors();
                                        reset();
                                    }
                                ">
                                    {{ t('delete_account.cancel') }}
                                </Button>
                            </DialogClose>

                            <Button type="submit" variant="destructive" :disabled="processing"> {{
                                t('delete_account.delete_button') }} </Button>
                        </DialogFooter>
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
