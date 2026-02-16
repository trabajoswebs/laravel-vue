<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch, useId } from 'vue';
import { Camera, ImagePlus, Upload, X } from 'lucide-vue-next';

import { Button } from '@/components/ui/button';
import InlineStatus from '@/components/ui/InlineStatus.vue';

import { useAvatarUpload } from '@/composables/useAvatarUpload';
import { useInitials } from '@/composables/useInitials';
import { useLanguage } from '@/composables/useLanguage';
import type { User } from '@/types';
import { notify } from '@/plugins/toaster-plugin';

// ============================================================================
// TYPES
// ============================================================================

interface Props {
    /** Usuario objetivo (si no se provee, se usa el autenticado) */
    user?: User | null;
    /** Texto de ayuda adicional (opcional) */
    helperText?: string;
    /** Ruta personalizada para subir el avatar */
    uploadRoute?: string;
    /** Ruta personalizada para eliminar el avatar */
    deleteRoute?: string;
}

interface DragState {
    isActive: boolean;
    canActivate: boolean;
}

// ============================================================================
// PROPS & COMPOSABLES
// ============================================================================

const props = withDefaults(defineProps<Props>(), {
    user: null,
    helperText: '',
    uploadRoute: undefined,
    deleteRoute: undefined,
});

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
    recentlySuccessful,
    successMessage,
    uploadAvatar,
    removeAvatar,
    resolveAvatarUrl,
    cancelUpload,
    constraints,
    allowedMimeSummary,
    formatBytesLabel,
    acceptMimeTypes,
} = useAvatarUpload({
    uploadRoute: props.uploadRoute,
    deleteRoute: props.deleteRoute,
});

// ============================================================================
// IDs FOR ACCESSIBILITY
// ============================================================================

const baseId = useId();
const uploadInputId = `${baseId}-input`;
const helperId = `${baseId}-helper`;

// ============================================================================
// LOCAL STATE
// ============================================================================

const fileInput = ref<HTMLInputElement | null>(null);
const dragState = ref<DragState>({ isActive: false, canActivate: true });
const previewUrl = ref<string | null>(null);
const isImageLoading = ref(false);
const suppressSkeleton = ref(false);

// ============================================================================
// COMPUTED PROPERTIES
// ============================================================================

// User & Avatar
const targetUser = computed<User | null>(() => props.user ?? authUser.value ?? null);
const displayName = computed<string>(() => targetUser.value?.name ?? '');
const avatarUrl = computed<string | null>(() => resolveAvatarUrl(targetUser.value));
const renderedAvatarUrl = computed<string | null>(() => previewUrl.value ?? avatarUrl.value);

// Status flags
const isBusy = computed<boolean>(() => isUploading.value || isDeleting.value);
const isUploadCancellable = computed<boolean>(() => isUploading.value);
const isDragActive = computed<boolean>(() => dragState.value.isActive);

// Progress
const visualProgress = computed<number>(() => {
    if (uploadProgress.value === null) return 0;
    return Math.max(6, uploadProgress.value); // Mínimo 6% para visibilidad
});

// UI states
const shouldShowSkeleton = computed<boolean>(() => {
    return isImageLoading.value &&
        Boolean(renderedAvatarUrl.value) &&
        !previewUrl.value &&
        !isUploading.value &&
        !suppressSkeleton.value;
});

const showUploadOverlay = computed<boolean>(() => isUploading.value);
const showCameraHint = computed<boolean>(() => !isImageLoading.value && !isUploading.value);
const showDeleteButton = computed<boolean>(() => hasAvatar.value && !isUploading.value);

// Messages
const helperMessage = computed<string>(() => {
    if (props.helperText.trim()) return props.helperText;
    return t('profile.avatar_helper_dynamic', {
        types: allowedMimeSummary,
        max: formatBytesLabel(constraints.maxBytes),
        min: constraints.minDimension,
    });
});

const inlineSuccessMessage = computed<string>(() =>
    successMessage.value || t('profile.avatar_upload_success_toast')
);

const inlineErrorMessage = computed<string>(() => {
    const fieldErrors = errors.value.avatar;
    if (Array.isArray(fieldErrors) && fieldErrors.length > 0) return fieldErrors[0];
    return generalError.value ?? '';
});

// Localized content
const localizedFormats = computed<string[]>(() => [
    'profile.avatar_format_jpg',
    'profile.avatar_format_jpeg',
    'profile.avatar_format_png',
    'profile.avatar_format_webp',
    'profile.avatar_format_avif',
    'profile.avatar_format_gif',
].map(key => t(key)));

const avatarImageAlt = computed<string>(() => {
    return displayName.value
        ? t('profile.avatar_image_alt_named', { name: displayName.value })
        : t('profile.avatar_image_alt_generic');
});

const dropHint = computed<string>(() =>
    isDragActive.value
        ? t('profile.avatar_drop_hint_active')
        : t('profile.avatar_drop_hint')
);

const acceptAttribute = computed<string>(() => acceptMimeTypes);

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

const clearPreview = (): void => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
    isImageLoading.value = false;
};

const resetInput = (): void => {
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

const setDragActive = (active: boolean): void => {
    if (!dragState.value.canActivate) return;
    dragState.value.isActive = active;
};

const isValidFileType = (file: File): boolean => {
    const allowedTypes = acceptMimeTypes.split(',').map(t => t.trim());
    return allowedTypes.includes(file.type) || allowedTypes.includes('*/*');
};

const handleError = (error: unknown): void => {
    const normalized = error as { toastShown?: boolean; aborted?: boolean; message?: string } | null;
    const alreadyHandled = normalized?.toastShown === true;
    const isAborted = normalized?.aborted === true;

    if (!alreadyHandled && !isAborted) {
        const fallback = normalized?.message?.trim() || t('profile.avatar_error_generic');
        notify.error(fallback);
    }

    if (import.meta.env.DEV) {
        console.warn('Avatar operation failed:', error);
    }
};

// ============================================================================
// FILE PROCESSING
// ============================================================================

const processFile = async (file: File | null): Promise<void> => {
    if (!file) return;

    // Client-side validation
    if (!isValidFileType(file)) {
        notify.error(t('profile.avatar_error_invalid_type'));
        return;
    }

    // Evita el skeleton durante el ciclo de subida/reemplazo del avatar.
    suppressSkeleton.value = true;
    clearPreview();
    previewUrl.value = URL.createObjectURL(file);
    resetInput();

    try {
        await uploadAvatar(file);
    } catch (error) {
        clearPreview();
        suppressSkeleton.value = false;
        handleError(error);
    } finally {
        setDragActive(false);
    }
};

// ============================================================================
// EVENT HANDLERS
// ============================================================================

const handleFileChange = async (event: Event): Promise<void> => {
    const input = event.target as HTMLInputElement | null;
    const file = input?.files?.[0] ?? null;
    await processFile(file);
};

const triggerFileDialog = (): void => {
    if (isBusy.value) return;
    fileInput.value?.click();
};

const handleRemove = async (): Promise<void> => {
    try {
        await removeAvatar();
    } catch (error) {
        handleError(error);
    }
};

const handleCancelUpload = (): void => {
    cancelUpload();
    resetInput();
    clearPreview();
    suppressSkeleton.value = false;
    setDragActive(false);
};

// Drag & Drop handlers
const handleDragActivate = (event: DragEvent): void => {
    event.preventDefault();
    setDragActive(true);
};

const handleDragLeave = (event: DragEvent): void => {
    event.preventDefault();
    const currentTarget = event.currentTarget as HTMLElement | null;
    const relatedTarget = event.relatedTarget as Node | null;

    // Solo desactivar si realmente sale del elemento
    if (currentTarget && relatedTarget && currentTarget.contains(relatedTarget)) {
        return;
    }
    setDragActive(false);
};

const handleDragEnd = (event: DragEvent): void => {
    event.preventDefault();
    setDragActive(false);
};

const handleDrop = async (event: DragEvent): Promise<void> => {
    event.preventDefault();
    setDragActive(false);

    if (isBusy.value) return;

    const droppedFile = event.dataTransfer?.files?.[0] ?? null;
    await processFile(droppedFile);
};

// Image load handlers
const handleImageLoad = (): void => {
    isImageLoading.value = false;

    // Solo liberamos el bloqueo del skeleton cuando termina de cargar la imagen remota.
    if (!previewUrl.value && !isUploading.value) {
        suppressSkeleton.value = false;
    }
};

const handleImageError = (): void => {
    isImageLoading.value = false;

    // Si falla la imagen remota tras una subida, también liberamos el bloqueo.
    if (!previewUrl.value && !isUploading.value) {
        suppressSkeleton.value = false;
    }
};

// ============================================================================
// LIFECYCLE & WATCHERS
// ============================================================================

onBeforeUnmount(() => {
    clearPreview();
});

// Limpiar preview cuando la subida termina
watch([avatarUrl, isUploading], ([nextUrl, uploading]) => {
    if (!uploading && nextUrl) {
        clearPreview();
    }
});

// Controlar carga de imagen (solo para URLs remotas, no blobs)
watch(renderedAvatarUrl, (nextUrl) => {
    const isLocalPreview = typeof nextUrl === 'string' && nextUrl.startsWith('blob:');
    isImageLoading.value = Boolean(nextUrl) && !isLocalPreview;
}, { immediate: true });

// Controlar capacidad de arrastre según estado de ocupación
watch(isBusy, (busy) => {
    dragState.value.canActivate = !busy;
    if (busy) {
        dragState.value.isActive = false;
    }
});
</script>

<template>
    <section
        class="avatar-drop-zone relative overflow-hidden rounded-2xl border-2 border-dashed border-border/60 bg-muted/20 p-6 shadow-md transition-all duration-300 ease-out"
        :class="{
            'avatar-drop-zone--active border-primary/60 ring-2 ring-primary/30': isDragActive,
            'hover:border-primary/40 hover:shadow-xl': !isDragActive
        }" @dragenter.prevent="handleDragActivate" @dragover.prevent="handleDragActivate"
        @dragleave.prevent="handleDragLeave" @drop.prevent="handleDrop" @dragend.prevent="handleDragEnd" role="region"
        :aria-label="t('profile.avatar_uploader_region')">
        <!-- Drag & Drop Overlay -->
        <div v-if="isDragActive" class="drag-overlay" aria-hidden="true">
            <div class="drag-overlay__content">
                <div class="drag-overlay__icon">
                    <span class="drag-overlay__icon-ring" />
                    <span class="drag-overlay__icon-pulse" />
                    <Upload class="drag-overlay__icon-svg" />
                </div>
                <p class="drag-overlay__title">{{ dropHint }}</p>
                <p class="drag-overlay__subtitle" v-once>{{ t('profile.avatar_drag_tip') }}</p>
                <div class="drag-overlay__pill">
                    <span>{{ localizedFormats.slice(0, 3).join(' • ') }}</span>
                    <span class="drag-overlay__pill-label">{{ localizedFormats.join(' • ') }}</span>
                    <span class="drag-overlay__pill-divider" aria-hidden="true" />
                    <span v-once>{{ t('profile.avatar_max_size_value') }}</span>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-6">
            <!-- Main Row: Avatar Preview + Info + Actions -->
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
                <!-- Avatar Column -->
                <div class="flex flex-col items-center gap-3 lg:items-start">
                    <!-- Avatar Button -->
                    <div class="relative">
                        <button type="button"
                            class="group relative h-32 w-32 lg:h-36 lg:w-36 rounded-2xl border-4 border-border/50 bg-gradient-to-br from-muted to-muted/50 shadow-xl transition-all duration-300 group-hover:shadow-2xl cursor-pointer focus-visible:ring-2 focus-visible:ring-primary/60 focus-visible:ring-offset-2 focus-visible:ring-offset-card"
                            :class="{ 'ring-4 ring-primary/30': isDragActive }"
                            :aria-label="hasAvatar ? t('profile.avatar_change_button') : t('profile.avatar_upload_button')"
                            :disabled="isBusy" :aria-disabled="isBusy" @click="triggerFileDialog"
                            @keydown.enter.prevent="triggerFileDialog" @keydown.space.prevent="triggerFileDialog">
                            <div class="relative h-full w-full rounded-2xl overflow-hidden">
                                <!-- Avatar Image or Preview -->
                                <template v-if="renderedAvatarUrl">
                                    <img :src="renderedAvatarUrl" :alt="avatarImageAlt"
                                        class="h-full w-full object-cover transition-opacity duration-200"
                                        :class="isImageLoading ? 'opacity-0' : 'opacity-100'" @load="handleImageLoad"
                                        @error="handleImageError" />

                                    <!-- Loading Skeleton -->
                                    <div v-if="shouldShowSkeleton"
                                        class="absolute inset-0 flex items-center justify-center p-5">
                                        <div class="avatar-skeleton">
                                            <div class="avatar-skeleton__frame shimmer">
                                                <span class="avatar-skeleton__receipt" />
                                                <span class="avatar-skeleton__line avatar-skeleton__line--1" />
                                                <span class="avatar-skeleton__line avatar-skeleton__line--2" />
                                                <span class="avatar-skeleton__line avatar-skeleton__line--3" />
                                                <span class="avatar-skeleton__chart" />
                                                <span class="avatar-skeleton__bar avatar-skeleton__bar--1" />
                                                <span class="avatar-skeleton__bar avatar-skeleton__bar--2" />
                                                <span class="avatar-skeleton__bar avatar-skeleton__bar--3" />
                                                <span class="avatar-skeleton__coin" />
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Camera Icon on Hover -->
                                    <div v-if="showCameraHint"
                                        class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/30 transition-colors duration-200 pointer-events-none">
                                        <Camera
                                            class="h-10 w-10 text-white/0 drop-shadow-md transition-all duration-200 group-hover:text-white/80" />
                                    </div>
                                </template>

                                <!-- Fallback: User Initials -->
                                <div v-else
                                    class="h-full w-full flex items-center justify-center bg-gradient-to-br from-primary/10 to-primary/5">
                                    <span class="text-3xl font-bold text-primary/60">
                                        {{ displayName ? getInitials(displayName) : '?' }}
                                    </span>
                                </div>

                                <!-- Upload Progress Overlay -->
                                <div v-if="showUploadOverlay" class="avatar-upload-overlay">
                                    <div class="avatar-upload-overlay__glyph" role="status" aria-live="polite">
                                        <span class="avatar-upload-overlay__spinner" aria-hidden="true" />
                                        <span class="sr-only">{{ t('profile.avatar_uploading_short') }}</span>
                                    </div>
                                </div>

                                <!-- Upload Indicator (no avatar state) -->
                                <div v-if="!renderedAvatarUrl && !isUploading"
                                    class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-black/0 opacity-0 transition-all duration-200 group-hover:bg-black/85 group-hover:opacity-100">
                                    <ImagePlus
                                        class="h-10 w-10 text-transparent transition-all duration-200 group-hover:text-white" />
                                </div>
                            </div>
                        </button>

                        <!-- Delete Button -->
                        <Button v-if="showDeleteButton" type="button" variant="destructive" size="icon"
                            @click.stop="handleRemove" :disabled="isBusy"
                            class="absolute -top-2 -right-2 h-8 w-8 rounded-full shadow-lg cursor-pointer transition-all duration-200 hover:scale-110 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card"
                            :aria-label="t('profile.avatar_remove_button')">
                            <img src="/icons/trash.svg" alt="" aria-hidden="true" class="h-4 w-4" />
                        </Button>

                        <!-- Progress Bar -->
                        <div v-if="isUploading && uploadProgress !== null"
                            class="mt-2 w-32 lg:w-36 h-2 bg-muted/50 rounded-full overflow-hidden shadow-inner"
                            role="progressbar" :aria-valuenow="visualProgress" aria-valuemin="0" aria-valuemax="100"
                            :aria-label="t('profile.avatar_uploading_short')">
                            <div class="h-full bg-gradient-to-r from-primary to-primary/80 transition-all duration-300 shadow-sm"
                                :style="{ width: `${visualProgress}%` }" />
                        </div>
                    </div>

                    <!-- Cancel Upload Button -->
                    <Button v-if="isUploadCancellable" type="button" size="xs" @click="handleCancelUpload"
                        class="warning-button cursor-pointer gap-1.5 rounded-md border border-transparent px-3 py-1.5 text-[12px] font-semibold shadow-sm hover:brightness-110 hover:scale-105 disabled:hover:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card">
                        <X class="h-4 w-4" />
                        <span>{{ t('profile.avatar_cancel_upload') }}</span>
                    </Button>
                </div>

                <!-- Info & Actions Column -->
                <div class="flex-1 flex flex-col gap-4">
                    <div>
                        <h3 class="text-xl font-bold text-foreground mb-1">
                            {{ displayName || t('profile.avatar_title') }}
                        </h3>
                        <p class="text-sm leading-relaxed text-foreground/70" v-once>
                            {{ t('profile.avatar_description') }}
                        </p>
                    </div>

                    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3">
                        <Button type="button" :disabled="isBusy" @click="triggerFileDialog"
                            class="cursor-pointer gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card">
                            <Upload class="h-4 w-4" />
                            <span>{{ hasAvatar ? t('profile.avatar_change_button') : t('profile.avatar_upload_button')
                            }}</span>
                        </Button>
                        <p class="text-xs text-foreground/70">
                            {{ localizedFormats.join(', ') }} · {{ t('profile.avatar_max_size_value') }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- Specifications Row -->
            <div class="grid gap-4 lg:grid-cols-[2fr,1.4fr]">
                <!-- Technical Specs -->
                <div class="p-4 rounded-lg bg-muted/40 border border-border/50 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <h4 class="text-xs font-semibold text-foreground mb-2 uppercase tracking-wider" v-once>
                                {{ t('profile.avatar_formats_title') }}
                            </h4>
                            <div class="flex flex-wrap gap-2">
                                <span v-for="format in localizedFormats" :key="format"
                                    class="inline-flex items-center px-2.5 py-1 rounded-md bg-primary/10 text-primary text-[10px] font-medium">
                                    {{ format }}
                                </span>
                            </div>
                        </div>
                        <div class="space-y-1">
                            <h4 class="text-xs font-semibold text-foreground mb-2 uppercase tracking-wider" v-once>
                                {{ t('profile.avatar_max_size_title') }}
                            </h4>
                            <p class="text-sm font-semibold text-foreground" v-once>
                                {{ t('profile.avatar_max_size_value') }}
                            </p>
                            <p class="text-xs leading-relaxed text-foreground/70" v-once>
                                {{ t('profile.avatar_recommended_dimensions') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Drag & Drop Tip -->
                <div class="flex flex-col gap-2 p-4 rounded-lg bg-primary/5 border border-dashed border-primary/30">
                    <div class="flex items-start gap-2.5">
                        <Upload class="h-4 w-4 text-primary flex-shrink-0 mt-0.5" />
                        <p class="text-xs leading-relaxed text-foreground/70" v-once>
                            {{ t('profile.avatar_drag_tip') }}
                        </p>
                    </div>
                    <slot name="extra-tips" />
                </div>
            </div>

            <!-- Status Messages -->
            <InlineStatus :show="Boolean(inlineErrorMessage)" :message="inlineErrorMessage" variant="error" role="alert"
                aria-live="assertive" />
            <InlineStatus :show="recentlySuccessful" :message="inlineSuccessMessage" variant="success" role="status"
                aria-live="polite" />
        </div>

        <!-- Hidden Helper Text for Screen Readers -->
        <p :id="helperId" class="sr-only">
            {{ helperMessage }}
        </p>

        <!-- Hidden File Input -->
        <label :for="uploadInputId" class="sr-only">
            {{ t('profile.avatar_upload_button') }}
        </label>
        <input :id="uploadInputId" ref="fileInput" class="hidden" type="file" :accept="acceptAttribute"
            :disabled="isBusy" :aria-describedby="helperId" @change="handleFileChange" />
    </section>
</template>

<style scoped>
/* ============================================================================
   ANIMATIONS
   ========================================================================= */
@keyframes fade-in {
    from {
        opacity: 0;
    }

    to {
        opacity: 1;
    }
}

@keyframes slide-in-from-top-2 {
    from {
        transform: translateY(-0.5rem);
    }

    to {
        transform: translateY(0);
    }
}

@keyframes avatar-shimmer {
    100% {
        transform: translateX(100%);
    }
}

@keyframes avatar-upload-rotate {
    100% {
        transform: rotate(360deg);
    }
}

@keyframes drag-overlay-slide {
    from {
        transform: translateX(-10%);
    }

    to {
        transform: translateX(10%);
    }
}

@keyframes drag-overlay-pulse {

    0%,
    100% {
        opacity: 0.45;
        transform: scale(0.85);
    }

    50% {
        opacity: 0.9;
        transform: scale(1);
    }
}

@keyframes drag-overlay-ring {
    100% {
        transform: rotate(360deg);
    }
}

/* ============================================================================
   BASE STYLES
   ========================================================================= */
.avatar-drop-zone {
    position: relative;
    --avatar-primary: var(--primary);
    --avatar-primary-foreground: var(--primary-foreground);
    --avatar-foreground: var(--foreground);
    --avatar-muted-foreground: var(--muted-foreground);
    --avatar-card: var(--card);
    --avatar-border: var(--border);
}

.avatar-drop-zone--active {
    box-shadow: 0 35px 80px -35px color-mix(in srgb, var(--avatar-primary) 55%, transparent);
}

/* ============================================================================
   DRAG OVERLAY
   ========================================================================= */
.drag-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.5rem;
    border-radius: 1.5rem;
    pointer-events: none;
    overflow: hidden;
    backdrop-filter: blur(4px);
    background-color: color-mix(in srgb, var(--avatar-primary) 15%, transparent);
}

.drag-overlay::before,
.drag-overlay::after {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: inherit;
}

.drag-overlay::before {
    inset: -15%;
    opacity: 0.95;
}

.drag-overlay::after {
    background-image: repeating-linear-gradient(45deg,
            rgba(255, 255, 255, 0.1) 0px,
            rgba(255, 255, 255, 0.1) 2px,
            transparent 2px,
            transparent 8px);
    background-size: 28px 28px;
    opacity: 0.35;
    animation: drag-overlay-slide 12s linear infinite;
    mix-blend-mode: screen;
}

.drag-overlay__content {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.35rem;
    text-align: center;
    color: var(--avatar-foreground);
}

.drag-overlay__title {
    font-size: 1.35rem;
    font-weight: 700;
    letter-spacing: 0.02em;
    text-shadow: 0 10px 35px color-mix(in srgb, var(--avatar-primary) 45%, transparent);
}

.drag-overlay__subtitle {
    font-size: 0.95rem;
    max-width: 22rem;
    color: color-mix(in srgb, var(--avatar-foreground) 80%, var(--avatar-muted-foreground) 20%);
}

.drag-overlay__pill {
    margin-top: 0.75rem;
    display: inline-flex;
    align-items: center;
    gap: 0.6rem;
    border-radius: 999px;
    padding: 0.45rem 0.9rem;
    border: 1px solid color-mix(in srgb, var(--avatar-primary-foreground) 45%, transparent);
    background: color-mix(in srgb, var(--avatar-primary) 25%, var(--avatar-card));
    font-size: 0.75rem;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    font-weight: 600;
    color: var(--avatar-primary-foreground);
}

.drag-overlay__pill-divider {
    width: 6px;
    height: 6px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--avatar-primary-foreground) 75%, transparent);
}

.drag-overlay__icon {
    position: relative;
    width: 4.6rem;
    height: 4.6rem;
    margin-bottom: 0.35rem;
    display: flex;
    align-items: center;
    justify-content: center;
}

.drag-overlay__icon-ring {
    position: absolute;
    inset: 0;
    border-radius: 999px;
    border: 2px solid color-mix(in srgb, var(--avatar-primary) 40%, transparent);
    animation: drag-overlay-ring 16s linear infinite;
}

.drag-overlay__icon-pulse {
    position: absolute;
    inset: 0.65rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--avatar-primary) 50%, transparent);
    filter: blur(4px);
    animation: drag-overlay-pulse 2s ease-in-out infinite;
}

.drag-overlay__icon-svg {
    position: relative;
    width: 2.4rem;
    height: 2.4rem;
    display: block;
    color: var(--avatar-primary);
    filter: drop-shadow(0 10px 22px color-mix(in srgb, var(--avatar-primary) 60%, transparent));
}

/* ============================================================================
   UPLOAD OVERLAY
   ========================================================================= */
.avatar-upload-overlay {
    position: absolute;
    inset: 0;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0.75rem;
    backdrop-filter: blur(2px);
    background: linear-gradient(160deg,
            color-mix(in srgb, var(--avatar-card) 54%, transparent),
            color-mix(in srgb, var(--avatar-primary) 16%, transparent));
}

.avatar-upload-overlay__glyph {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, var(--avatar-border) 70%, transparent);
    background: color-mix(in srgb, var(--avatar-card) 90%, transparent);
    box-shadow:
        0 8px 22px -14px color-mix(in srgb, var(--avatar-foreground) 42%, transparent),
        inset 0 1px 0 color-mix(in srgb, var(--avatar-primary-foreground) 14%, transparent);
}

.avatar-upload-overlay__spinner {
    width: 1.45rem;
    height: 1.45rem;
    border-radius: 999px;
    border: 2px solid color-mix(in srgb, var(--avatar-primary) 28%, var(--avatar-border));
    border-top-color: var(--avatar-primary);
    animation: avatar-upload-rotate 0.8s linear infinite;
}

/* ============================================================================
   AVATAR SKELETON
   ========================================================================= */
.avatar-skeleton {
    width: 100%;
    height: 100%;
}

.avatar-skeleton__frame {
    position: relative;
    width: 100%;
    height: 100%;
    border-radius: 1rem;
    background: color-mix(in srgb, var(--avatar-card) 94%, var(--avatar-foreground) 6%);
    border: 1px solid color-mix(in srgb, var(--avatar-border) 80%, transparent);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--avatar-border) 35%, transparent);
    overflow: hidden;
}

.avatar-skeleton__receipt {
    position: absolute;
    top: 14%;
    left: 14%;
    width: 72%;
    height: 72%;
    border-radius: 0.8rem;
    border: 1px solid color-mix(in srgb, var(--avatar-primary) 24%, var(--avatar-border));
    background: linear-gradient(180deg,
            color-mix(in srgb, var(--avatar-card) 85%, transparent),
            color-mix(in srgb, var(--avatar-primary) 10%, transparent));
    box-shadow: inset 0 1px 0 color-mix(in srgb, var(--avatar-primary-foreground) 14%, transparent);
}

.avatar-skeleton__line {
    position: absolute;
    left: 22%;
    height: 0.3rem;
    border-radius: 999px;
    background: color-mix(in srgb, var(--avatar-primary) 28%, var(--avatar-border));
    opacity: 0.82;
}

.avatar-skeleton__line--1 {
    top: 24%;
    width: 52%;
}

.avatar-skeleton__line--2 {
    top: 31%;
    width: 42%;
}

.avatar-skeleton__line--3 {
    top: 38%;
    width: 48%;
}

.avatar-skeleton__chart {
    position: absolute;
    left: 22%;
    bottom: 21%;
    width: 56%;
    height: 24%;
    border-radius: 0.45rem;
    border: 1px solid color-mix(in srgb, var(--avatar-border) 82%, transparent);
    background: color-mix(in srgb, var(--avatar-card) 68%, transparent);
}

.avatar-skeleton__bar {
    position: absolute;
    bottom: 24%;
    width: 0.5rem;
    border-radius: 0.22rem 0.22rem 0.12rem 0.12rem;
    background: linear-gradient(180deg,
            color-mix(in srgb, var(--avatar-primary) 55%, transparent),
            color-mix(in srgb, var(--avatar-primary) 82%, transparent));
}

.avatar-skeleton__bar--1 {
    left: 30%;
    height: 8%;
}

.avatar-skeleton__bar--2 {
    left: 39%;
    height: 12%;
}

.avatar-skeleton__bar--3 {
    left: 48%;
    height: 16%;
}

.avatar-skeleton__coin {
    position: absolute;
    right: 17%;
    bottom: 20%;
    width: 0.95rem;
    height: 0.95rem;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, var(--avatar-primary) 40%, transparent);
    background: color-mix(in srgb, var(--avatar-primary) 22%, transparent);
}

.shimmer {
    position: relative;
    overflow: hidden;
    isolation: isolate;
}

.shimmer::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(120deg,
            transparent,
            color-mix(in srgb, var(--avatar-primary-foreground) 35%, transparent),
            transparent);
    transform: translateX(-100%);
    animation: avatar-shimmer 1.8s ease-in-out infinite;
    z-index: 1;
}

/* ============================================================================
   UTILITIES
   ========================================================================= */
.shadow-lg {
    box-shadow:
        0 10px 25px -5px color-mix(in srgb, var(--avatar-foreground) 10%, transparent),
        0 8px 10px -6px color-mix(in srgb, var(--avatar-foreground) 8%, transparent);
}

.shadow-xl {
    box-shadow:
        0 20px 35px -10px color-mix(in srgb, var(--avatar-foreground) 15%, transparent),
        0 10px 20px -5px color-mix(in srgb, var(--avatar-foreground) 10%, transparent);
}

.shadow-2xl {
    box-shadow: 0 25px 50px -12px color-mix(in srgb, var(--avatar-foreground) 25%, transparent);
}

.bg-gradient-to-br {
    background-image: linear-gradient(to bottom right, var(--tw-gradient-stops));
}

.warning-button {
    background-color: var(--toast-warning-accent);
    color: var(--primary-foreground);
}

button {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

button:focus-visible {
    outline: none;
}
</style>
