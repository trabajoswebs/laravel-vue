<script setup lang="ts">
// Importaciones de Vue y bibliotecas externas
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { Camera, ImagePlus, Upload, X } from 'lucide-vue-next';

// Importaciones de componentes UI
import { Button } from '@/components/ui/button';
import InlineStatus from '@/components/ui/InlineStatus.vue';

// Importaciones de composables personalizados
import { useAvatarUpload } from '@/composables/useAvatarUpload';
import { useInitials } from '@/composables/useInitials';
import { useLanguage } from '@/composables/useLanguage';
import type { User } from '@/types';
import { notify } from '@/plugins/toaster-plugin';

// Definición de props del componente
interface Props {
    user?: User | null; // Usuario para mostrar el avatar, opcional
    helperText?: string; // Texto de ayuda adicional, opcional
    uploadRoute?: string; // Ruta o URL personalizada para subir el avatar
    deleteRoute?: string; // Ruta o URL personalizada para eliminar el avatar
}

const props = withDefaults(defineProps<Props>(), {
    user: null, // Valor por defecto para user
    helperText: '', // Valor por defecto para helperText
    uploadRoute: undefined,
    deleteRoute: undefined,
});

// Referencias y IDs
const fileInput = ref<HTMLInputElement | null>(null); // Referencia al input de archivo para abrirlo programáticamente
const baseId = useId() ?? `avatar-upload-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`; // ID único para el componente
const uploadInputId = `${baseId}-input`; // ID para el input de archivo
const helperId = `${baseId}-helper`; // ID para el texto de ayuda
const errorId = `${baseId}-error`; // ID para mensajes de error
const progressId = `${baseId}-progress`; // ID para la barra de progreso

// Instancias de composables
const { t } = useLanguage(); // Función para traducir textos
const { getInitials } = useInitials(); // Función para obtener iniciales del nombre

// Estado y funciones para la subida de avatar
const {
    authUser, // Usuario autenticado
    hasAvatar, // Indica si el usuario tiene avatar
    isUploading, // Indica si hay una subida en curso
    isDeleting, // Indica si hay una eliminación en curso
    uploadProgress, // Progreso de la subida (0-100)
    errors, // Errores de validación
    generalError, // Error general
    recentlySuccessful, // Flag de éxito inline
    successMessage, // Mensaje de éxito inline
    uploadAvatar, // Función para subir avatar
    removeAvatar, // Función para eliminar avatar
    resolveAvatarUrl, // Función para obtener URL del avatar
    cancelUpload, // Función para cancelar subida
    constraints, // Restricciones de subida
    allowedMimeSummary, // Resumen de tipos MIME permitidos
    formatBytesLabel, // Función para formatear bytes
    acceptMimeTypes, // Tipos MIME aceptados
} = useAvatarUpload({
    uploadRoute: props.uploadRoute,
    deleteRoute: props.deleteRoute,
});

// Computadas
const targetUser = computed<User | null>(() => props.user ?? authUser.value ?? null); // Usuario objetivo para el avatar
const avatarUrl = computed<string | null>(() => resolveAvatarUrl(targetUser.value)); // URL del avatar actual
const displayName = computed<string>(() => targetUser.value?.name ?? ''); // Nombre del usuario

// Texto alternativo para la imagen del avatar
const avatarImageAlt = computed<string>(() => {
    if (displayName.value) {
        return t('profile.avatar_image_alt_named', { name: displayName.value });
    }
    return t('profile.avatar_image_alt_generic');
});

// Progreso visual para la barra (con mínimo para visibilidad)
const visualProgress = computed<number>(() => {
    if (uploadProgress.value === null) return 0;
    const value = uploadProgress.value;
    if (value > 0 && value < 6) return 6; // Mínimo 6% para visibilidad
    return value;
});

// Vista previa
const previewUrl = ref<string | null>(null); // URL de vista previa del archivo
const renderedAvatarUrl = computed<string | null>(() => previewUrl.value ?? avatarUrl.value); // URL a mostrar (vista previa o avatar actual)
const UPLOAD_VISUAL_TEST_DELAY_MS = 0; // Retardo temporal para pruebas de UX
const uploadVisualLock = ref(false); // Mantiene visible el estado de subida aunque la red responda muy rápido

// Mensajes
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

// Mensaje de error del avatar
const avatarErrorMessage = computed<string>(() => {
    const fieldErrors = errors.value.avatar;
    if (Array.isArray(fieldErrors) && fieldErrors.length > 0) {
        return fieldErrors[0];
    }
    return generalError.value ?? '';
});

// Estados
const isUploadingUi = computed<boolean>(() => isUploading.value || uploadVisualLock.value); // Estado de subida mostrado en UI (incluye retardo de prueba)
const isBusy = computed<boolean>(() => isUploadingUi.value || isDeleting.value); // Indica si hay operación en curso
const isUploadCancellable = computed<boolean>(() => isUploading.value); // Indica si la subida se puede cancelar
const acceptAttribute = computed<string>(() => acceptMimeTypes); // Tipos MIME permitidos
const isDragActive = ref(false); // Indica si hay arrastre activo
const inlineSuccessMessage = computed(() => successMessage.value || t('profile.avatar_upload_success_toast'));
const inlineErrorMessage = computed(() => generalError.value || avatarErrorMessage.value || '');

// Establece el estado de arrastre
const setDragState = (active: boolean) => {
    if (active && isBusy.value) return;
    isDragActive.value = active;
};

// Texto del hint de drop
const dropHint = computed<string>(() =>
    isDragActive.value
        ? t('profile.avatar_drop_hint_active')
        : t('profile.avatar_drop_hint')
);

// Claves para los formatos localizados
const formatLocaleKeys = [
    'profile.avatar_format_jpg',
    'profile.avatar_format_jpeg',
    'profile.avatar_format_png',
    'profile.avatar_format_webp',
    'profile.avatar_format_avif',
    'profile.avatar_format_gif',
] as const;

// Formatso localizados
const localizedFormats = computed<string[]>(() => formatLocaleKeys.map((key) => t(key)));

const activeUploadToken = ref<symbol | null>(null); // Token para identificar la subida activa
const isImageLoading = ref(false); // Indica si la imagen está cargando
const hasUploadStartedInSession = ref(false); // Evita skeleton tras iniciar una subida en esta sesión
const shouldShowAvatarSkeleton = computed<boolean>(() =>
    isImageLoading.value
    && Boolean(renderedAvatarUrl.value)
    && !previewUrl.value
    && !isUploadingUi.value
    && !hasUploadStartedInSession.value
);

const wait = (ms: number) =>
    new Promise<void>((resolve) => {
        window.setTimeout(resolve, ms);
    });

// Limpia la vista previa y restablece estado
const clearPreview = () => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value); // Libera la URL de objeto
        previewUrl.value = null;
    }
    isImageLoading.value = false;
};

// Limpia la vista previa al desmontar
onBeforeUnmount(() => {
    clearPreview();
});

// Limpia la vista previa cuando se completa la subida
watch([avatarUrl, isUploading], ([next, uploading]) => {
    if (!uploading && next) {
        clearPreview();
    }
});

// Actualiza el estado de carga de la imagen
watch(
    renderedAvatarUrl,
    (nextUrl) => {
        const isLocalPreview = typeof nextUrl === 'string' && nextUrl.startsWith('blob:');
        isImageLoading.value = Boolean(nextUrl) && !isLocalPreview;
    },
    { immediate: true }
);

// Evita que el overlay quede activo mientras hay acciones en curso
watch(isBusy, (busy) => {
    if (busy) {
        setDragState(false);
    }
});

// Abre el diálogo de selección de archivo
const triggerFileDialog = () => {
    if (isBusy.value) return;
    fileInput.value?.click();
};

// Resetea el input de archivo
const resetInput = () => {
    if (fileInput.value) {
        fileInput.value.value = '';
    }
};

// Aplica la vista previa de la imagen
const applyPreview = (file: File | null) => {
    clearPreview();
    if (file) {
        previewUrl.value = URL.createObjectURL(file); // Crea URL de objeto para vista previa
    }
};

// Procesa el archivo subido
const processFile = async (file: File | null) => {
    const token = Symbol('avatar-upload'); // Token único para esta subida
    const startedAt = Date.now();
    hasUploadStartedInSession.value = true;
    activeUploadToken.value = token;
    uploadVisualLock.value = true;
    applyPreview(file);

    try {
        await uploadAvatar(file ?? undefined);
    } catch (error) {
        if (activeUploadToken.value !== token) return;
        clearPreview();
        if (import.meta.env.DEV) {
            const e = error as { status?: number; aborted?: boolean; name?: string };
            const status = e?.status;
            const aborted = e?.aborted === true || e?.name === 'AbortError';
            const expected = status === 422 || aborted;
            const unexpected = !expected && (status === undefined || status >= 500 || status === 0);
            if (unexpected) {
                console.warn('Avatar upload failed:', error);
                notify.error(t('profile.avatar_error_generic'));
            }
        } else {
            notify.error(t('profile.avatar_error_generic'));
        }
    } finally {
        const elapsed = Date.now() - startedAt;
        const remainingDelay = Math.max(0, UPLOAD_VISUAL_TEST_DELAY_MS - elapsed);
        if (remainingDelay > 0) {
            await wait(remainingDelay);
        }

        if (activeUploadToken.value === token) {
            resetInput();
            activeUploadToken.value = null;
            setDragState(false);
        }

        uploadVisualLock.value = false;
    }
};

// Maneja el cambio de archivo
const handleFileChange = async (event: Event) => {
    const input = event.target as HTMLInputElement | null;
    const file = input?.files?.[0] ?? null;
    await processFile(file);
};

// Maneja la eliminación del avatar
const handleRemove = async () => {
    try {
        await removeAvatar();
    } catch (error) {
        notify.error(t('profile.avatar_error_generic'));
        if (import.meta.env.DEV) console.warn('Avatar removal failed:', error);
    }
};

// Maneja la cancelación de la subida
const handleCancelUpload = () => {
    cancelUpload();
    resetInput();
    activeUploadToken.value = null;
    uploadVisualLock.value = false;
    clearPreview();
    setDragState(false);
};

// Maneja el evento dragenter
const handleDragActivate = (event: DragEvent) => {
    event.preventDefault();
    setDragState(true); // Mantiene el estado de arrastre sincronizado entre dragenter/dragover
};

// Maneja el evento dragleave
const handleDragLeave = (event: DragEvent) => {
    event.preventDefault();
    const currentTarget = event.currentTarget as HTMLElement | null;
    const relatedTarget = event.relatedTarget as Node | null;

    if (currentTarget && relatedTarget && currentTarget.contains(relatedTarget)) {
        return;
    }

    setDragState(false);
};

// Maneja el evento dragend
const handleDragEnd = (event: DragEvent) => {
    event.preventDefault();
    setDragState(false);
};

// Maneja el evento drop
const handleDrop = async (event: DragEvent) => {
    event.preventDefault();
    setDragState(false);
    if (isBusy.value) return;
    const droppedFile = event.dataTransfer?.files?.[0] ?? null;
    if (!droppedFile) return;
    await processFile(droppedFile);
};

// Maneja la carga de la imagen
const handleImageLoad = () => {
    isImageLoading.value = false; // Marca la imagen como cargada
};

// Maneja el error de carga de la imagen
const handleImageError = () => {
    isImageLoading.value = false; // Marca la imagen como fallida
};
</script>

<template>
    <section
        class="avatar-drop-zone relative overflow-hidden rounded-2xl border-2 border-dashed border-border/60 bg-muted/20 p-6 shadow-md transition-all duration-300 ease-out"
        :class="{
            'avatar-drop-zone--active border-primary/60 ring-2 ring-primary/30': isDragActive,
            'hover:border-primary/40 hover:shadow-xl': !isDragActive
        }" @dragenter.prevent="handleDragActivate" @dragover.prevent="handleDragActivate"
        @dragleave.prevent="handleDragLeave" @drop.prevent="handleDrop" @dragend.prevent="handleDragEnd" role="region"
        aria-label="Profile avatar uploader">
        <!-- Overlay drag & drop -->
        <div v-if="isDragActive" class="drag-overlay">
            <div class="drag-overlay__content">
                <div class="drag-overlay__icon" aria-hidden="true">
                    <span class="drag-overlay__icon-ring"></span>
                    <span class="drag-overlay__icon-pulse"></span>
                    <Upload class="drag-overlay__icon-svg" />
                </div>
                <p class="drag-overlay__title">
                    {{ dropHint }}
                </p>
                <p class="drag-overlay__subtitle">
                    {{ t('profile.avatar_drag_tip') }}
                </p>
                <div class="drag-overlay__pill">
                    <span>{{ localizedFormats.slice(0, 3).join(' • ') }}</span>
                    <span class="drag-overlay__pill-label">{{ localizedFormats.join(' • ') }}</span>
                    <span class="drag-overlay__pill-divider" aria-hidden="true"></span>
                    <span>{{ t('profile.avatar_max_size_value') }}</span>
                </div>
            </div>
        </div>

        <!-- LAYOUT PRINCIPAL -->
        <div class="flex flex-col gap-6">
            <!-- FILA 1: PREVIEW + INFO + CTA -->
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start">
                <!-- Columna izquierda: Avatar -->
                <div class="flex flex-col items-center gap-3 lg:items-start">
                    <div class="relative">
                        <div class="group relative h-32 w-32 lg:h-36 lg:w-36 rounded-2xl border-4 border-border/50 bg-gradient-to-br from-muted to-muted/50 shadow-xl transition-all duration-300 group-hover:shadow-2xl cursor-pointer focus-visible:ring-2 focus-visible:ring-primary/60 focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary/70"
                            :class="{ 'ring-4 ring-primary/30': isDragActive }" :aria-label="avatarImageAlt"
                            role="button" tabindex="0" @click="triggerFileDialog"
                            @keydown.enter.prevent="triggerFileDialog" @keydown.space.prevent="triggerFileDialog">
                            <div class="relative h-full w-full rounded-2xl overflow-hidden">
                                <!-- Imagen (ya subida o vista previa local) -->
                                <template v-if="renderedAvatarUrl">
                                    <img :src="renderedAvatarUrl" :alt="avatarImageAlt"
                                        class="h-full w-full object-cover transition-opacity duration-200"
                                        :class="isImageLoading ? 'opacity-0' : 'opacity-100'" @load="handleImageLoad"
                                        @error="handleImageError" @click.stop="triggerFileDialog" />
                                    <div v-if="shouldShowAvatarSkeleton"
                                        class="absolute inset-0 flex items-center justify-center p-5">
                                        <div class="avatar-skeleton">
                                            <div class="avatar-skeleton__frame shimmer">
                                                <span class="avatar-skeleton__receipt"></span>
                                                <span class="avatar-skeleton__line avatar-skeleton__line--1"></span>
                                                <span class="avatar-skeleton__line avatar-skeleton__line--2"></span>
                                                <span class="avatar-skeleton__line avatar-skeleton__line--3"></span>
                                                <span class="avatar-skeleton__chart"></span>
                                                <span class="avatar-skeleton__bar avatar-skeleton__bar--1"></span>
                                                <span class="avatar-skeleton__bar avatar-skeleton__bar--2"></span>
                                                <span class="avatar-skeleton__bar avatar-skeleton__bar--3"></span>
                                                <span class="avatar-skeleton__coin"></span>
                                            </div>
                                        </div>
                                    </div>
                                    <div v-if="!isImageLoading && !isUploading"
                                        class="absolute inset-0 flex items-center justify-center bg-black/0 group-hover:bg-black/30 transition-colors duration-200 pointer-events-none">
                                        <Camera
                                            class="h-10 w-10 text-white/0 drop-shadow-md transition-all duration-200 group-hover:text-white/80" />
                                    </div>
                                </template>

                                <!-- Iniciales -->
                                <div v-else
                                    class="h-full w-full flex items-center justify-center bg-gradient-to-br from-primary/10 to-primary/5 cursor-pointer"
                                    role="button" tabindex="-1" @click.stop="triggerFileDialog">
                                    <span class="text-3xl font-bold text-primary/60">
                                        {{ displayName ? getInitials(displayName) : '?' }}
                                    </span>
                                </div>

                                <!-- Máscara de subida: mantiene la imagen visible para evitar salto visual -->
                                <div v-if="isUploadingUi" class="avatar-upload-overlay">
                                    <div class="avatar-upload-overlay__glyph" role="status" aria-live="polite">
                                        <span class="avatar-upload-overlay__spinner" aria-hidden="true"></span>
                                        <span class="sr-only">{{ t('profile.avatar_uploading_short') }}</span>
                                    </div>
                                </div>

                                <!-- Indicador hover "subir" cuando no hay avatar -->
                                <div v-if="!renderedAvatarUrl && !isUploadingUi"
                                    class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-black/0 opacity-0 transition-all duration-200 group-hover:bg-black/85 group-hover:opacity-100">
                                    <ImagePlus
                                        class="h-10 w-10 text-transparent transition-all duration-200 group-hover:text-white" />
                                </div>
                            </div>
                        </div>
                        <!-- ÚNICO control eliminar -->
                        <Button v-if="hasAvatar && !isUploadingUi" type="button" variant="destructive" size="icon"
                            @click.stop="handleRemove" :disabled="isBusy"
                            class="absolute -top-2 -right-2 h-8 w-8 rounded-full shadow-lg cursor-pointer transition-all duration-200 hover:scale-110 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card"
                            :aria-label="t('profile.avatar_remove_button')" :title="t('profile.avatar_remove_button')">
                            <img src="/icons/trash.svg" alt="" aria-hidden="true" class="h-4 w-4" />
                        </Button>

                        <!-- Progreso bajo avatar -->
                        <div v-if="isUploadingUi && uploadProgress !== null"
                            class="mt-2 w-32 lg:w-36 h-2 bg-muted/50 rounded-full overflow-hidden shadow-inner"
                            role="progressbar" :aria-valuenow="visualProgress" aria-valuemin="0" aria-valuemax="100"
                            :aria-describedby="progressId">
                            <div class="h-full bg-gradient-to-r from-primary to-primary/80 transition-all duration-300 shadow-sm"
                                :style="{ width: `${visualProgress}%` }" />
                        </div>
                    </div>

                    <!-- Cancelar subida -->
                    <Button v-if="isUploadCancellable" type="button" size="xs" @click="handleCancelUpload"
                        class="warning-button cursor-pointer gap-1.5 rounded-md border border-transparent px-3 py-1.5 text-[12px] font-semibold shadow-sm hover:brightness-110 hover:scale-105 disabled:hover:scale-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card">
                        <X class="h-4 w-4" />
                        <span>{{ t('profile.avatar_cancel_upload') }}</span>
                    </Button>
                </div>

                <!-- Columna derecha: texto + CTA principal -->
                <div class="flex-1 flex flex-col gap-4">
                    <div>
                        <h3 class="text-xl font-bold text-foreground mb-1">
                            {{ displayName || t('profile.avatar_title') }}
                        </h3>
                        <p class="text-sm leading-relaxed text-foreground/70">
                            {{ t('profile.avatar_description') }}
                        </p>
                    </div>

                    <!-- Acción principal + hint compacto (solo formatos + tamaño) -->
                    <div class="flex flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3">
                        <Button type="button" :disabled="isBusy" @click="triggerFileDialog"
                            :aria-label="hasAvatar ? t('profile.avatar_change_button') : t('profile.avatar_upload_button')"
                            class="cursor-pointer gap-2 rounded-lg px-5 py-2.5 text-sm font-semibold hover:brightness-110 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card">
                            <Upload class="h-4 w-4" />
                            <span v-if="hasAvatar">
                                {{ t('profile.avatar_change_button') }}
                            </span>
                            <span v-else>
                                {{ t('profile.avatar_upload_button') }}
                            </span>
                        </Button>

                        <p class="text-xs text-foreground/70">
                            {{ localizedFormats.join(', ') }} · {{ t('profile.avatar_max_size_value') }}
                        </p>
                    </div>
                </div>
            </div>

            <!-- FILA 2: Especificaciones + Consejo -->
            <div class="grid gap-4 lg:grid-cols-[2fr,1.4fr]">
                <!-- Especificaciones técnicas -->
                <div class="p-4 rounded-lg bg-muted/40 border border-border/50 space-y-3">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <h4 class="text-xs font-semibold text-foreground mb-2 uppercase tracking-wider">
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
                            <h4 class="text-xs font-semibold text-foreground mb-2 uppercase tracking-wider">
                                {{ t('profile.avatar_max_size_title') }}
                            </h4>
                            <p class="text-sm font-semibold text-foreground">
                                {{ t('profile.avatar_max_size_value') }}
                            </p>
                            <p class="text-xs leading-relaxed text-foreground/70">
                                {{ t('profile.avatar_recommended_dimensions') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Consejo: arrastrar y soltar (único lugar donde se menciona) -->
                <div class="flex flex-col gap-2 p-4 rounded-lg bg-primary/5 border border-dashed border-primary/30">
                    <div class="flex items-start gap-2.5">
                        <Upload class="h-4 w-4 text-primary flex-shrink-0 mt-0.5" />
                        <p class="text-xs leading-relaxed text-foreground/70">
                            {{ t('profile.avatar_drag_tip') }}
                        </p>
                    </div>
                    <slot name="extra-tips"></slot>
                </div>
            </div>

            <!-- Mensajes inline -->
            <InlineStatus :show="Boolean(inlineErrorMessage)" :message="inlineErrorMessage" variant="error" />
            <InlineStatus :show="recentlySuccessful" :message="inlineSuccessMessage" variant="success" />
        </div>

        <p :id="helperId" class="sr-only">
            {{ helperMessage }}
        </p>

        <!-- Input real -->
        <label class="sr-only" :for="uploadInputId">
            {{ t('profile.avatar_upload_button') }}
        </label>
        <input :id="uploadInputId" ref="fileInput" class="hidden" type="file" :accept="acceptAttribute"
            :disabled="isBusy" :aria-describedby="`${helperId} ${errorId}`" @change="handleFileChange" />
    </section>
</template>

<style scoped>
/* ---------------------------------------
 * Animaciones básicas
 * ------------------------------------- */
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

.animate-in {
    animation: fade-in 0.2s ease-out, slide-in-from-top-2 0.2s ease-out;
}

/* ---------------------------------------
 * Zona de drop del avatar
 * ------------------------------------- */
.avatar-drop-zone {
    position: relative;

    /* Tokens locales basados en tu tema global */
    --avatar-primary: var(--primary);
    --avatar-primary-foreground: var(--primary-foreground);
    --avatar-foreground: var(--foreground);
    --avatar-muted-foreground: var(--muted-foreground);
    --avatar-card: var(--card);
    --avatar-border: var(--border);
}

.avatar-drop-zone--active {
    /* Sombra basada en el primario, no en un verde hardcodeado */
    box-shadow: 0 35px 80px -35px color-mix(in srgb, var(--avatar-primary) 55%, transparent);
}

/* ---------------------------------------
 * Overlay de drag & drop
 * ------------------------------------- */
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
    /* Usa el primario global para el velo */
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
    /* Glow basado en el primario del tema */
    text-shadow: 0 10px 35px color-mix(in srgb, var(--avatar-primary) 45%, transparent);
}

.drag-overlay__subtitle {
    font-size: 0.95rem;
    max-width: 22rem;
    /* Mezcla foreground con muted-foreground de tu tema */
    color: color-mix(in srgb,
            var(--avatar-foreground) 80%,
            var(--avatar-muted-foreground) 20%);
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
    /* Sombra coherente con el primario del tema */
    filter: drop-shadow(0 10px 22px color-mix(in srgb, var(--avatar-primary) 60%, transparent));
}

/* ---------------------------------------
 * Sombras (ajustadas a foreground)
 * ------------------------------------- */
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
    box-shadow:
        0 25px 50px -12px color-mix(in srgb, var(--avatar-foreground) 25%, transparent);
}

/* ---------------------------------------
 * Overlay de subida del avatar
 * ------------------------------------- */
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

/* ---------------------------------------
 * Skeleton del avatar
 * ------------------------------------- */
.avatar-skeleton {
    width: 100%;
    height: 100%;
}

.avatar-skeleton__frame {
    position: relative;
    width: 100%;
    height: 100%;
    border-radius: 1rem;
    /* Fondo y borde basados en card/border para que respete claro/oscuro */
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

/* ---------------------------------------
 * Efecto shimmer
 * ------------------------------------- */
.shimmer {
    position: relative;
    overflow: hidden;
    isolation: isolate;
}

.shimmer::after {
    content: '';
    position: absolute;
    inset: 0;
    /* Usa el color de texto claro del tema para el brillo */
    background: linear-gradient(120deg,
            transparent,
            color-mix(in srgb, var(--avatar-primary-foreground) 35%, transparent),
            transparent);
    transform: translateX(-100%);
    animation: avatar-shimmer 1.8s ease-in-out infinite;
    z-index: 1;
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

/* ---------------------------------------
 * Keyframes overlay drag
 * ------------------------------------- */
@keyframes drag-overlay-slide {
    from {
        transform: translateX(-10%);
    }

    to {
        transform: translateX(10%);
    }
}

@keyframes drag-overlay-pulse {
    0% {
        opacity: 0.45;
        transform: scale(0.85);
    }

    50% {
        opacity: 0.9;
        transform: scale(1);
    }

    100% {
        opacity: 0.45;
        transform: scale(0.85);
    }
}

@keyframes drag-overlay-ring {
    100% {
        transform: rotate(360deg);
    }
}

/* ---------------------------------------
 * Utilidades locales
 * ------------------------------------- */

/* NO sobreescribas aquí los .bg-primary/text-primary/border-primary de Tailwind.
   Si quieres mantenerlas para este componente, que apunten a los tokens globales. */
.bg-primary {
    background-color: var(--primary);
}

.text-primary {
    color: var(--primary);
}

.border-primary {
    border-color: var(--primary);
}

/* Transición genérica de botones solo dentro del componente */
button {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

button:focus-visible {
    outline: none;
}

/* Mantén la semántica de Tailwind para el gradiente */
.bg-gradient-to-br {
    background-image: linear-gradient(to bottom right, var(--tw-gradient-stops));
}

/* Botón de warning usando tokens de toasts y primario del tema */
.warning-button {
    background-color: var(--toast-warning-accent);
    color: var(--primary-foreground);
}
</style>
