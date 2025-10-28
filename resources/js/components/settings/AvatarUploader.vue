<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Loader2, Trash2, Upload } from 'lucide-vue-next';

import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { useAvatarUpload } from '@/composables/useAvatarUpload';
import { useInitials } from '@/composables/useInitials';
import { useLanguage } from '@/composables/useLanguage';
import type { User } from '@/types';

interface Props {
    /**
     * Usuario a mostrar. Si no se provee, se usará el autenticado desde Inertia.
     */
    user?: User | null;
    /**
     * Texto de ayuda opcional; si no se entrega, se usará la traducción por defecto.
     */
    helperText?: string;
}

const props = withDefaults(defineProps<Props>(), {
    user: null,
    helperText: '',
});

const fileInput = ref<HTMLInputElement | null>(null);
const baseId = useId() ?? `avatar-upload-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
const uploadInputId = `${baseId}-input`;
const helperId = `${baseId}-helper`;
const errorId = `${baseId}-error`;
const progressId = `${baseId}-progress`;

const { t } = useLanguage();
const { getInitials } = useInitials();

const {
    authUser,
    hasAvatar,
    isUploading,
    isDeleting,
    uploadProgress,
    errors,
    generalError,
    uploadAvatar,
    removeAvatar,
    resolveAvatarUrl,
    cancelUpload,
    constraints,
    allowedMimeSummary,
    formatBytesLabel,
    acceptMimeTypes,
} = useAvatarUpload();

const targetUser = computed<User | null>(() => props.user ?? authUser.value ?? null);
const avatarUrl = computed<string | null>(() => resolveAvatarUrl(targetUser.value));
const displayName = computed<string>(() => targetUser.value?.name ?? '');
const avatarImageAlt = computed<string>(() => {
    if (displayName.value) {
        return t('profile.avatar_image_alt_named', { name: displayName.value });
    }

    return t('profile.avatar_image_alt_generic');
});
const uploadPercentage = computed<number>(() => {
    if (uploadProgress.value === null) {
        return 0;
    }

    return Math.round(uploadProgress.value);
});
const visualProgress = computed<number>(() => {
    if (uploadProgress.value === null) {
        return 0;
    }

    const value = uploadProgress.value;
    if (value > 0 && value < 6) {
        return 6;
    }

    return value;
});
const previewUrl = ref<string | null>(null);
const renderedAvatarUrl = computed<string | null>(() => previewUrl.value ?? avatarUrl.value);

const helperMessage = computed<string>(() => {
    if (props.helperText && props.helperText.trim() !== '') {
        return props.helperText;
    }

    return t('profile.avatar_helper_dynamic', {
        types: allowedMimeSummary,
        max: formatBytesLabel(constraints.maxBytes),
        min: constraints.minDimension,
    });
});

const avatarErrorMessage = computed<string>(() => {
    const fieldErrors = errors.value.avatar;
    if (Array.isArray(fieldErrors) && fieldErrors.length > 0) {
        return fieldErrors[0];
    }

    return generalError.value ?? '';
});

const isBusy = computed<boolean>(() => isUploading.value || isDeleting.value);
const isUploadCancellable = computed<boolean>(() => isUploading.value);
const acceptAttribute = computed<string>(() => acceptMimeTypes);
const isDragActive = ref(false);
const dropHint = computed<string>(() =>
    isDragActive.value
        ? t('profile.avatar_drop_hint_active')
        : t('profile.avatar_drop_hint')
);
const activeUploadToken = ref<symbol | null>(null);
const clearPreview = () => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
};

onBeforeUnmount(() => {
    clearPreview();
});

watch([avatarUrl, isUploading], ([next, uploading]) => {
    if (!uploading && next) {
        clearPreview();
    }
});

const triggerFileDialog = () => {
    if (isBusy.value) {
        return;
    }
    fileInput.value?.click();
};

const resetInput = () => {
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const applyPreview = (file: File | null) => {
    clearPreview();
    if (file) {
        previewUrl.value = URL.createObjectURL(file);
    }
};

const processFile = async (file: File | null) => {
    const token = Symbol('avatar-upload');
    activeUploadToken.value = token;
    applyPreview(file);

    try {
        const result = await uploadAvatar(file ?? undefined);
        if (activeUploadToken.value === token) {
            toast.success(t('profile.avatar_upload_success_toast'), {
                description: t('profile.avatar_upload_success_details', {
                    filename: result.filename,
                    width: result.width,
                    height: result.height,
                }),
            });
        }
    } catch (error) {
        if (activeUploadToken.value !== token) {
            return;
        }

        clearPreview();

        const description =
            (error instanceof Error ? error.message : generalError.value) ??
            t('profile.avatar_error_generic');

        toast.error(t('profile.avatar_upload_failed_toast'), {
            description,
        });

        if (import.meta.env.DEV) {
            console.warn('Avatar upload failed:', error);
        }
    } finally {
        if (activeUploadToken.value === token) {
            resetInput();
            activeUploadToken.value = null;
            isDragActive.value = false;
        }
    }
};

const handleFileChange = async (event: Event) => {
    const input = event.target as HTMLInputElement | null;
    const file = input?.files?.[0] ?? null;
    await processFile(file);
};

const handleRemove = async () => {
    try {
        await removeAvatar();
        toast.success(t('profile.avatar_remove_success_toast'));
    } catch (error) {
        const description =
            (error instanceof Error ? error.message : generalError.value) ??
            t('profile.avatar_error_generic');

        toast.error(t('profile.avatar_remove_failed_toast'), {
            description,
        });

        if (import.meta.env.DEV) {
            console.warn('Avatar removal failed:', error);
        }
    }
};

const handleCancelUpload = () => {
    cancelUpload();
    resetInput();
    activeUploadToken.value = null;
    clearPreview();
    isDragActive.value = false;
    toast.warning(t('profile.avatar_upload_cancelled_toast'));
};

const handleDragEnter = (event: DragEvent) => {
    event.preventDefault();
    if (isBusy.value) {
        return;
    }
    isDragActive.value = true;
};

const handleDragOver = (event: DragEvent) => {
    event.preventDefault();
    if (!isBusy.value) {
        isDragActive.value = true;
    }
};

const handleDragLeave = (event: DragEvent) => {
    event.preventDefault();
    if (event.currentTarget === event.target) {
        isDragActive.value = false;
    }
};

const handleDrop = async (event: DragEvent) => {
    event.preventDefault();
    if (isBusy.value) {
        return;
    }

    const file = event.dataTransfer?.files?.[0] ?? null;
    isDragActive.value = false;
    await processFile(file);
};
</script>

<template>
    <section class="rounded-2xl border border-border bg-card p-6 shadow-sm transition-colors"
        :class="{ 'ring-2 ring-primary/60': isDragActive }" @dragenter.prevent="handleDragEnter"
        @dragover.prevent="handleDragOver" @dragleave.prevent="handleDragLeave" @drop.prevent="handleDrop">
        <div class="flex flex-col gap-6 sm:flex-row sm:items-center sm:gap-8">
            <div class="flex flex-col items-center gap-3 sm:items-start">
                <Avatar
                    class="relative h-24 w-24 shrink-0 overflow-hidden rounded-2xl border border-border shadow-inner">
                    <AvatarImage v-if="renderedAvatarUrl" :src="renderedAvatarUrl" :alt="avatarImageAlt" />
                    <AvatarFallback
                        class="flex items-center justify-center rounded-xl bg-muted text-lg font-medium text-foreground"
                        aria-hidden="true">
                        <div v-if="!renderedAvatarUrl && isUploading"
                            class="h-full w-full animate-pulse rounded-xl bg-muted-foreground/10" />
                        <span v-else>
                            {{ displayName ? getInitials(displayName) : '??' }}
                        </span>
                    </AvatarFallback>
                    <div v-if="isDragActive"
                        class="absolute inset-0 flex items-center justify-center rounded-2xl border-2 border-dashed border-primary/60 bg-primary/10"
                        aria-hidden="true" />
                </Avatar>

                <p :id="helperId" class="max-w-xs text-center text-xs text-muted-foreground sm:text-left">
                    {{ helperMessage }}
                </p>
                <p
                    class="text-center text-[11px] font-medium uppercase tracking-wide text-muted-foreground sm:text-left">
                    {{ dropHint }}
                </p>
            </div>

            <div class="flex flex-1 flex-col gap-4">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                    <Button type="button" :disabled="isBusy" :aria-label="t('profile.avatar_upload_button')"
                        @click="triggerFileDialog"
                        class="inline-flex items-center gap-2 rounded-full bg-foreground px-4 py-2 text-sm font-semibold text-background shadow-sm transition hover:bg-foreground/90 disabled:cursor-not-allowed disabled:opacity-70">
                        <Loader2 v-if="isUploading" class="h-4 w-4 animate-spin" />
                        <Upload v-else class="h-4 w-4" />
                        <span>
                            {{ isUploading ? t('profile.avatar_uploading_short') : t('profile.avatar_upload_button') }}
                        </span>
                    </Button>

                    <Button type="button" variant="outline" :disabled="isBusy || !hasAvatar"
                        :aria-label="t('profile.avatar_remove_button')" @click="handleRemove"
                        class="inline-flex items-center gap-2 rounded-full border border-destructive/60 px-4 py-2 text-sm font-semibold text-destructive transition hover:bg-destructive/10 disabled:cursor-not-allowed disabled:opacity-50">
                        <Loader2 v-if="isDeleting" class="h-4 w-4 animate-spin" />
                        <Trash2 v-else class="h-4 w-4" />
                        <span>
                            {{ isDeleting ? t('profile.avatar_deleting_short') : t('profile.avatar_remove_button') }}
                        </span>
                    </Button>
                </div>

                <div v-if="isUploading && uploadProgress !== null" class="space-y-2">
                    <div class="h-2 w-full overflow-hidden rounded-full bg-muted" role="progressbar"
                        :aria-valuenow="visualProgress" aria-valuemin="0" aria-valuemax="100"
                        :aria-describedby="progressId">
                        <div class="h-full rounded-full bg-foreground/80 transition-all duration-300"
                            :style="{ width: `${visualProgress}%` }"></div>
                    </div>
                    <p :id="progressId" class="text-xs text-muted-foreground" aria-live="polite">
                        {{ t('profile.avatar_uploading_progress', { progress: uploadPercentage }) }}
                    </p>
                    <Button v-if="isUploadCancellable" type="button" size="sm" variant="ghost"
                        class="rounded-full border border-border px-3 py-1 text-xs font-medium text-foreground transition hover:bg-muted"
                        @click="handleCancelUpload">
                        {{ t('profile.avatar_cancel_upload') }}
                    </Button>
                </div>

                <p v-else-if="isDeleting" class="text-xs text-muted-foreground" aria-live="polite">
                    {{ t('profile.avatar_deleting_status') }}
                </p>

                <InputError class="mt-1" :id="errorId" :message="avatarErrorMessage" />
            </div>
        </div>

        <input :id="uploadInputId" ref="fileInput" class="hidden" type="file" :accept="acceptAttribute"
            :disabled="isBusy" :aria-describedby="`${helperId} ${errorId}`" @change="handleFileChange">
    </section>
</template>
