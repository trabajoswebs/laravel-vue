<script setup lang="ts">
// Importaciones de Vue y bibliotecas externas
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Camera, ImagePlus, Loader2, Upload, X } from 'lucide-vue-next';

// Importaciones de componentes UI
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';

// Importaciones de composables personalizados
import { useAvatarUpload } from '@/composables/useAvatarUpload';
import { useInitials } from '@/composables/useInitials';
import { useLanguage } from '@/composables/useLanguage';
import type { User } from '@/types';

// Definición de props del componente
interface Props {
    user?: User | null;
    helperText?: string;
}

const props = withDefaults(defineProps<Props>(), {
    user: null,
    helperText: '',
});

// Referencias y IDs
const fileInput = ref<HTMLInputElement | null>(null); // Referencia al input de archivo
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

// Computadas
const targetUser = computed<User | null>(() => props.user ?? authUser.value ?? null); // Usuario objetivo para el avatar
const avatarUrl = computed<string | null>(() => resolveAvatarUrl(targetUser.value)); // URL del avatar actual
const displayName = computed<string>(() => targetUser.value?.name ?? ''); // Nombre del usuario

const avatarImageAlt = computed<string>(() => {
    if (displayName.value) {
        return t('profile.avatar_image_alt_named', { name: displayName.value });
    }
    return t('profile.avatar_image_alt_generic');
});

const uploadPercentage = computed<number>(() => {
    if (uploadProgress.value === null) return 0;
    return Math.round(uploadProgress.value);
});

const visualProgress = computed<number>(() => {
    if (uploadProgress.value === null) return 0;
    const value = uploadProgress.value;
    if (value > 0 && value < 6) return 6; // Mínimo 6% para visibilidad
    return value;
});

// Vista previa
const previewUrl = ref<string | null>(null); // URL de vista previa del archivo
const renderedAvatarUrl = computed<string | null>(() => previewUrl.value ?? avatarUrl.value); // URL a mostrar (vista previa o avatar actual)

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

const avatarErrorMessage = computed<string>(() => {
    const fieldErrors = errors.value.avatar;
    if (Array.isArray(fieldErrors) && fieldErrors.length > 0) {
        return fieldErrors[0];
    }
    return generalError.value ?? '';
});

// Estados
const isBusy = computed<boolean>(() => isUploading.value || isDeleting.value); // Indica si hay operación en curso
const isUploadCancellable = computed<boolean>(() => isUploading.value); // Indica si la subida se puede cancelar
const acceptAttribute = computed<string>(() => acceptMimeTypes); // Tipos MIME permitidos
const isDragActive = ref(false); // Indica si hay arrastre activo

const setDragState = (active: boolean) => {
    if (active && isBusy.value) return;
    isDragActive.value = active;
};

const dropHint = computed<string>(() =>
    isDragActive.value
        ? t('profile.avatar_drop_hint_active')
        : t('profile.avatar_drop_hint')
);

const formatLocaleKeys = [
    'profile.avatar_format_jpg',
    'profile.avatar_format_jpeg',
    'profile.avatar_format_png',
    'profile.avatar_format_webp',
    'profile.avatar_format_avif',
    'profile.avatar_format_gif',
] as const;

const localizedFormats = computed<string[]>(() => formatLocaleKeys.map((key) => t(key)));

const hasImage = computed<boolean>(() => Boolean(renderedAvatarUrl.value));

const activeUploadToken = ref<symbol | null>(null); // Token para identificar la subida activa
const isImageLoading = ref(false); // Indica si la imagen está cargando

type AvatarUploadResult = Awaited<ReturnType<typeof uploadAvatar>>;

const clearPreview = () => {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value); // Libera la URL de objeto
        previewUrl.value = null;
    }
    isImageLoading.value = false;
};

onBeforeUnmount(() => {
    clearPreview(); // Limpia la vista previa al desmontar
});

watch([avatarUrl, isUploading], ([next, uploading]) => {
    if (!uploading && next) {
        clearPreview(); // Limpia la vista previa cuando se completa la subida
    }
});

watch(
    renderedAvatarUrl,
    (nextUrl) => {
        isImageLoading.value = Boolean(nextUrl); // Actualiza estado de carga
    },
    { immediate: true }
);

watch(isBusy, (busy) => {
    if (busy) {
        setDragState(false); // Evita que el overlay quede activo mientras hay acciones en curso
    }
});

const triggerFileDialog = () => {
    if (isBusy.value) return;
    fileInput.value?.click(); // Abre el diálogo de selección de archivo
};

const resetInput = () => {
    if (fileInput.value) {
        fileInput.value.value = ''; // Resetea el input de archivo
    }
};

const applyPreview = (file: File | null) => {
    clearPreview();
    if (file) {
        previewUrl.value = URL.createObjectURL(file); // Crea URL de objeto para vista previa
    }
};

const buildErrorDescription = (error: unknown) =>
    (error instanceof Error ? error.message : generalError.value) ?? t('profile.avatar_error_generic');

const notifyFailure = (titleKey: string, error: unknown, logContext: string) => {
    toast.error(t(titleKey), {
        description: buildErrorDescription(error),
    });

    if (import.meta.env.DEV) {
        console.warn(logContext, error);
    }
};

const showUploadSuccessToast = (result: AvatarUploadResult) => {
    toast.success(t('profile.avatar_upload_success_toast'), {
        description: t('profile.avatar_upload_success_details', {
            filename: result.filename,
            width: result.width,
            height: result.height,
        }),
    });
};

const processFile = async (file: File | null) => {
    const token = Symbol('avatar-upload'); // Token único para esta subida
    activeUploadToken.value = token;
    applyPreview(file);

    try {
        const result = await uploadAvatar(file ?? undefined);
        if (activeUploadToken.value === token) {
            showUploadSuccessToast(result);
        }
    } catch (error) {
        if (activeUploadToken.value !== token) return;
        clearPreview();
        notifyFailure('profile.avatar_upload_failed_toast', error, 'Avatar upload failed:');
    } finally {
        if (activeUploadToken.value === token) {
            resetInput();
            activeUploadToken.value = null;
            setDragState(false);
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
        notifyFailure('profile.avatar_remove_failed_toast', error, 'Avatar removal failed:');
    }
};

const handleCancelUpload = () => {
    cancelUpload();
    resetInput();
    activeUploadToken.value = null;
    clearPreview();
    setDragState(false);
    toast.warning(t('profile.avatar_upload_cancelled_toast'));
};

const handleDragActivate = (event: DragEvent) => {
    event.preventDefault();
    setDragState(true); // Mantiene el estado de arrastre sincronizado entre dragenter/dragover
};

const handleDragLeave = (event: DragEvent) => {
    event.preventDefault();
    const currentTarget = event.currentTarget as HTMLElement | null;
    const relatedTarget = event.relatedTarget as Node | null;

    if (currentTarget && relatedTarget && currentTarget.contains(relatedTarget)) {
        return;
    }

    setDragState(false);
};

const handleDragEnd = (event: DragEvent) => {
    event.preventDefault();
    setDragState(false);
};

const handleDrop = async (event: DragEvent) => {
    event.preventDefault();
    setDragState(false);
    if (isBusy.value) return;
    const droppedFile = event.dataTransfer?.files?.[0] ?? null;
    if (!droppedFile) return;
    await processFile(droppedFile);
};

const handleImageLoad = () => {
    isImageLoading.value = false; // Marca la imagen como cargada
};

const handleImageError = () => {
    isImageLoading.value = false; // Marca la imagen como fallida
};
</script>

<template>
    <section class="relative" :class="{
        'border-primary bg-primary/5 shadow-primary/20': isDragActive,
        'border-border hover:border-border/60': !isDragActive
    }" @dragenter.prevent="handleDragActivate" @dragover.prevent="handleDragActivate"
        @dragleave.prevent="handleDragLeave" @drop.prevent="handleDrop" @dragend.prevent="handleDragEnd" role="region"
        aria-label="Profile avatar uploader">
        <!-- Overlay drag & drop -->
        <div v-if="isDragActive"
            class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-4 rounded-xl bg-primary/10 backdrop-blur-sm pointer-events-none">
            <div class="relative">
                <div class="absolute inset-0 animate-ping rounded-full bg-primary/30"></div>
                <Upload class="relative h-16 w-16 text-primary" />
            </div>
            <p class="text-lg font-semibold text-primary">
                {{ dropHint }}
            </p>
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
                                <!-- Subida en curso -->
                                <div v-if="isUploading"
                                    class="h-full w-full flex flex-col items-center justify-center gap-3 bg-gradient-to-br from-primary/5 to-primary/10">
                                    <Loader2 class="h-8 w-8 animate-spin text-primary" />
                                    <div class="text-center">
                                        <p class="text-xs font-semibold text-foreground">
                                            {{ t('profile.avatar_uploading_short') }}
                                        </p>
                                        <p class="text-[10px] text-muted-foreground">
                                            {{ uploadPercentage }}%
                                        </p>
                                    </div>
                                </div>

                                <!-- Imagen (ya subida) -->
                                <template v-else-if="renderedAvatarUrl">
                                    <img :src="renderedAvatarUrl" :alt="avatarImageAlt"
                                        class="h-full w-full object-cover transition-opacity duration-200"
                                        :class="isImageLoading ? 'opacity-0' : 'opacity-100'" @load="handleImageLoad"
                                        @error="handleImageError" @click.stop="triggerFileDialog" />
                                    <div v-if="isImageLoading"
                                        class="absolute inset-0 flex items-center justify-center p-5">
                                        <div class="avatar-skeleton">
                                            <div class="avatar-skeleton__frame shimmer">
                                                <span class="avatar-skeleton__sun"></span>
                                                <span
                                                    class="avatar-skeleton__mountain avatar-skeleton__mountain--left"></span>
                                                <span
                                                    class="avatar-skeleton__mountain avatar-skeleton__mountain--right"></span>
                                                <span class="avatar-skeleton__baseline"></span>
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

                                <!-- Indicador hover "subir" cuando no hay avatar -->
                                <div v-if="!renderedAvatarUrl && !isUploading"
                                    class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-2xl bg-black/0 opacity-0 transition-all duration-200 group-hover:bg-black/85 group-hover:opacity-100">
                                    <ImagePlus
                                        class="h-10 w-10 text-transparent transition-all duration-200 group-hover:text-white" />
                                </div>
                            </div>
                        </div>
                        <!-- ÚNICO control eliminar -->
                        <Button v-if="hasAvatar && !isUploading" type="button" variant="destructive" size="icon"
                            @click.stop="handleRemove" :disabled="isBusy"
                            class="absolute -top-2 -right-2 h-8 w-8 rounded-full shadow-lg cursor-pointer transition-all duration-200 hover:scale-110 disabled:cursor-not-allowed focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/50 focus-visible:ring-offset-2 focus-visible:ring-offset-card"
                            :aria-label="t('profile.avatar_remove_button')" :title="t('profile.avatar_remove_button')">
                            <img src="/icons/trash.svg" alt="" aria-hidden="true" class="h-4 w-4" />
                        </Button>

                        <!-- Progreso bajo avatar -->
                        <div v-if="isUploading && uploadProgress !== null"
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

            <!-- Errores -->
            <InputError v-if="avatarErrorMessage" :id="errorId" :message="avatarErrorMessage"
                class="animate-in fade-in slide-in-from-top-2 duration-200" />
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

.shadow-lg {
    box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
}

.shadow-xl {
    box-shadow: 0 20px 35px -10px rgba(0, 0, 0, 0.15), 0 10px 20px -5px rgba(0, 0, 0, 0.1);
}

.shadow-2xl {
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.avatar-skeleton {
    width: 100%;
    height: 100%;
}

.avatar-skeleton__frame {
    position: relative;
    width: 100%;
    height: 100%;
    border-radius: 1rem;
    background: rgba(255, 255, 255, 0.04);
    border: 1px solid rgba(255, 255, 255, 0.08);
    box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.1);
    overflow: hidden;
}

.avatar-skeleton__sun {
    position: absolute;
    top: 18%;
    right: 18%;
    width: 2.8rem;
    height: 2.8rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.18);
    box-shadow: 0 0 25px rgba(0, 0, 0, 0.45);
}

.avatar-skeleton__mountain {
    position: absolute;
    bottom: 18%;
    width: 60%;
    height: 55%;
    background: linear-gradient(180deg, rgba(0, 0, 0, 0.2), rgba(0, 0, 0, 0.65));
    clip-path: polygon(0 100%, 50% 0, 100% 100%);
    opacity: 0.85;
}

.avatar-skeleton__mountain--left {
    left: -5%;
}

.avatar-skeleton__mountain--right {
    right: -5%;
    width: 50%;
    height: 45%;
    opacity: 0.6;
}

.avatar-skeleton__baseline {
    position: absolute;
    bottom: 0;
    left: 0;
    width: 100%;
    height: 22%;
    background: linear-gradient(180deg, transparent, rgba(0, 0, 0, 0.9));
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
    background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.35), transparent);
    transform: translateX(-100%);
    animation: avatar-shimmer 1.8s ease-in-out infinite;
    z-index: 1;
}

@keyframes avatar-shimmer {
    100% {
        transform: translateX(100%);
    }
}

.bg-primary {
    background-color: #18b463;
}

.text-primary {
    color: #18b463;
}

.border-primary {
    border-color: #18b463;
}

button {
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

button:focus-visible {
    outline: none;
}

.bg-gradient-to-br {
    background-image: linear-gradient(to bottom right, var(--tw-gradient-stops));
}

.warning-button {
    background-color: var(--toast-warning-accent);
    color: var(--primary-foreground);
}
</style>
