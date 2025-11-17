import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Config } from 'ziggy-js';
import { route } from 'ziggy-js';

import type { AppPageProps, User } from '@/types';

// Tipo para los errores que devuelve el backend
type ErrorBag = Record<string, string[]>;

// Restricciones para la validación del archivo de avatar
interface AvatarValidationConstraints {
    maxBytes: number; // Tamaño máximo en bytes (ej. 25 * 1024 * 1024)
    minDimension: number; // Dimensión mínima en píxeles (ej. 128)
    maxMegapixels: number; // Límite de megapíxeles (ancho * alto / 1e6) (ej. 48)
    allowedMimeTypes: readonly string[]; // Tipos MIME permitidos (ej. ['image/jpeg', 'image/png'])
}

// Opciones configurables para el hook
interface UseAvatarUploadOptions {
    /**
     * Nombre de la ruta de actualización (Ziggy) o URL absoluta.
     * @default 'settings.avatar.update'
     */
    uploadRoute?: string;
    /**
     * Nombre de la ruta de eliminación (Ziggy) o URL absoluta.
     * @default 'settings.avatar.destroy'
     */
    deleteRoute?: string;
    /**
     * Refrescar los props `auth` tras completar la operación.
     * @default true
     */
    refreshAuthOnSuccess?: boolean;
    /**
     * Restringe validaciones en cliente. Debe empatar con backend.
     */
    constraints?: Partial<AvatarValidationConstraints>;
}

// Resultado de la validación local del archivo
interface UploadValidationResult {
    width: number;
    height: number;
    bytes: number;
    sanitizedName: string; // Nombre limpio del archivo
}

// Representa una subida en curso que puede cancelarse
interface OngoingUpload {
    controller: AbortController | null;
    cancelRequest?: VoidFunction;
}

// Datos devueltos tras una subida exitosa
export interface AvatarUploadSuccessPayload {
    filename: string; // Nombre del archivo subido
    width: number; // Ancho de la imagen
    height: number; // Alto de la imagen
    bytes: number; // Tamaño del archivo en bytes
}

// Etiquetas para mostrar los tipos MIME en mensajes de error
// Exportado para su uso en componentes
export const MIME_EXTENSION_LABELS: Record<string, string> = {
    'image/jpeg': 'JPEG',
    'image/png': 'PNG',
    'image/gif': 'GIF',
    'image/webp': 'WebP',
    'image/avif': 'AVIF',
};

// Opciones por defecto
const DEFAULT_OPTIONS = {
    uploadRoute: 'settings.avatar.update',
    deleteRoute: 'settings.avatar.destroy',
    refreshAuthOnSuccess: true,
} as const satisfies Omit<Required<UseAvatarUploadOptions>, 'constraints'>;

// Restricciones por defecto
const DEFAULT_CONSTRAINTS: AvatarValidationConstraints = {
    maxBytes: 25 * 1024 * 1024, // 25MB
    minDimension: 128, // 128x128 px
    maxMegapixels: 48, // 48 megapíxeles
    allowedMimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'],
};

// Comprueba si el navegador soporta AbortController para cancelar peticiones
const SUPPORTS_ABORT = typeof AbortController !== 'undefined';
const FALLBACK_FILENAME = 'avatar';
const CACHE_BUSTER_PARAM = '_cb';
const IMAGE_READ_TIMEOUT_MS = 10_000; // Tiempo máximo para leer una imagen (10 segundos)
const LOCAL_HOSTNAMES = new Set(['localhost', '127.0.0.1', '0.0.0.0']);

/**
 * Verifica si una cadena es una URL absoluta (http:// o https://).
 * @param target Cadena a verificar.
 * @returns `true` si es una URL absoluta.
 */
function isAbsoluteUrl(target: string): boolean {
    return /^https?:\/\//i.test(target);
}

/**
 * Resuelve una ruta de Ziggy o una URL absoluta.
 * @param target Nombre de la ruta o URL.
 * @param ziggyConfig Configuración de Ziggy.
 * @returns URL resuelta.
 * @throws Error si no se puede resolver o falta la configuración.
 * @example resolveRoute('settings.avatar.update', config) -> '/user/avatar'
 * @example resolveRoute('https://api.example.com/upload', null) -> 'https://api.example.com/upload'
 */
function resolveRoute(target: string, ziggyConfig: Config | null): string {
    if (!target || typeof target !== 'string') {
        throw new Error('Route name or URL must be a non-empty string.');
    }

    if (target.startsWith('/')) {
        return target;
    }

    if (isAbsoluteUrl(target)) {
        return target;
    }

    if (!ziggyConfig) {
        throw new Error('Ziggy configuration is unavailable in page props.');
    }

    return route(target, undefined, false, ziggyConfig);
}

/**
 * Limpia un nombre de archivo de caracteres peligrosos o no deseados.
 * @param value Nombre original del archivo.
 * @returns Nombre limpio o un nombre por defecto.
 * @example sanitizeFilename('my avatar <script>.jpg') -> 'my avatar .jpg'
 */
function sanitizeFilename(value: string | undefined): string {
    if (!value || typeof value !== 'string') {
        return FALLBACK_FILENAME;
    }

    const cleaned = value
        .normalize('NFKC') // Normaliza caracteres Unicode
        .replace(/[^\p{L}\p{N}_. -]+/gu, '') // Elimina caracteres no permitidos
        .trim()
        .slice(0, 150); // Limita la longitud

    return cleaned.length > 0 ? cleaned : FALLBACK_FILENAME;
}

/**
 * Convierte un tamaño en bytes a una cadena legible (MB).
 * @param bytes Tamaño en bytes.
 * @param locale Locale para formatear el número.
 * @returns Cadena formateada (e.g., "2.5 MB").
 * @example formatBytes(2560000, 'es') -> "2.5 MB"
 * @example formatBytes(20971520, 'en') -> "20 MB"
 */
function formatBytes(bytes: number, locale: string): string {
    const megabytes = bytes / (1024 * 1024);
    const formatter = new Intl.NumberFormat(locale, {
        maximumFractionDigits: megabytes >= 10 ? 0 : 1, // 0 decimales si >= 10MB
    });

    return `${formatter.format(megabytes)} MB`;
}

/**
 * Lee las dimensiones reales de un archivo de imagen con un timeout.
 * Intenta usar createImageBitmap si está disponible, si no, usa Image().
 * @param file Archivo de imagen.
 * @returns Promesa que resuelve con {width, height}.
 * @example readImageDimensions(file) -> Promise<{width: 400, height: 400}>
 */
async function readImageDimensions(file: File): Promise<{ width: number; height: number }> {
    if (typeof window !== 'undefined' && 'createImageBitmap' in window && typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file);
            const dimensions = { width: bitmap.width, height: bitmap.height };
            if (typeof bitmap.close === 'function') {
                bitmap.close(); // Libera memoria
            }
            return dimensions;
        } catch (error) {
            console.warn('createImageBitmap failed, falling back to Image()', error);
        }
    }

    // Fallback a Image() con timeout
    return new Promise((resolve, reject) => {
        const image = new Image();
        const objectUrl = URL.createObjectURL(file);
        const timeoutId =
            typeof window !== 'undefined'
                ? window.setTimeout(() => {
                      URL.revokeObjectURL(objectUrl);
                      reject(new Error('Image decode timeout exceeded.'));
                  }, IMAGE_READ_TIMEOUT_MS)
                : undefined;

        image.decoding = 'async';
        image.onload = () => {
            if (timeoutId !== undefined) {
                clearTimeout(timeoutId);
            }

            const width = image.naturalWidth || image.width;
            const height = image.naturalHeight || image.height;
            URL.revokeObjectURL(objectUrl); // Libera el objeto URL

            if (!width || !height) {
                reject(new Error('Invalid image dimensions.'));
                return;
            }

            resolve({ width, height });
        };

        image.onerror = () => {
            if (timeoutId !== undefined) {
                clearTimeout(timeoutId);
            }

            URL.revokeObjectURL(objectUrl);
            reject(new Error('Unable to read image dimensions.'));
        };

        image.src = objectUrl;
    });
}

/**
 * Obtiene el origin actual a partir de Ziggy o window.location.
 */
function resolveCurrentOrigin(locationCandidate: string | null | undefined): string | null {
    const candidates = [locationCandidate];
    if (typeof window !== 'undefined' && window.location?.origin) {
        candidates.push(window.location.origin);
    }

    for (const candidate of candidates) {
        if (!candidate) {
            continue;
        }

        try {
            return new URL(candidate).origin;
        } catch {
            // Ignorar valores inválidos
        }
    }

    return null;
}

/**
 * Normaliza URLs de assets que apuntan a hosts locales no accesibles desde el navegador actual.
 * Si es necesario, reemplaza el host por el origin real de la aplicación.
 */
function normalizeAssetHost(url: string, preferredOrigin: string | null): string {
    if (!url) {
        return url;
    }

    try {
        const parsed = new URL(url);
        if (preferredOrigin && LOCAL_HOSTNAMES.has(parsed.hostname.toLowerCase())) {
            const preferred = new URL(preferredOrigin);
            parsed.protocol = preferred.protocol;
            parsed.host = preferred.host;
            return parsed.toString();
        }
        return parsed.toString();
    } catch {
        if (preferredOrigin && url.startsWith('/')) {
            return `${preferredOrigin}${url}`;
        }
        return url;
    }
}

/**
 * Detecta el tipo MIME real de un archivo leyendo sus primeros bytes (magic numbers).
 * @param file Archivo a inspeccionar.
 * @returns MIME type detectado o null.
 * @example detectMimeType(file) -> "image/jpeg"
 */
async function detectMimeType(file: File): Promise<string | null> {
    try {
        // Lee los primeros 12 bytes del archivo
        const buffer = await file.slice(0, 12).arrayBuffer();
        const bytes = new Uint8Array(buffer);

        // Comprueba las firmas mágicas
        if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
            return 'image/jpeg';
        }

        if (bytes.length >= 8 && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
            return 'image/png';
        }

        if (bytes.length >= 3 && bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46) {
            return 'image/gif';
        }

        if (bytes.length >= 12 && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50) {
            return 'image/webp';
        }

        if (
            bytes.length >= 12 &&
            bytes[4] === 0x66 &&
            bytes[5] === 0x74 &&
            bytes[6] === 0x79 &&
            bytes[7] === 0x70 &&
            bytes[8] === 0x61 &&
            bytes[9] === 0x76 &&
            bytes[10] === 0x69 &&
            bytes[11] === 0x66
        ) {
            return 'image/avif';
        }
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn('Unable to inspect file header for MIME detection.', error);
        }
    }

    return null;
}

/**
 * Crea un string resumen de tipos MIME permitidos para mostrar al usuario.
 * @param mimeTypes Lista de MIME types.
 * @returns String formateado.
 * @example buildAllowedSummary(['image/jpeg', 'image/png']) -> "JPEG, PNG"
 */
function buildAllowedSummary(mimeTypes: readonly string[]): string {
    return mimeTypes.map((mime) => MIME_EXTENSION_LABELS[mime] ?? mime).join(', ');
}

/**
 * Extrae la extensión de un nombre de archivo.
 * @param name Nombre del archivo.
 * @returns Extensión en minúsculas o null.
 * @example getExtensionFromName('foto.png') -> "png"
 * @example getExtensionFromName('archivo') -> null
 */
function getExtensionFromName(name: string | undefined): string | null {
    if (!name) {
        return null;
    }

    const match = name.split('.').pop();
    if (!match) {
        return null;
    }

    return match.trim().toLowerCase();
}

/**
 * Verifica si un archivo coincide con los tipos MIME o extensiones permitidas.
 * NOTA: Esta validación es solo para UX. El backend debe validar el archivo real.
 * @param file Archivo a comprobar.
 * @param allowed Conjunto de MIME types permitidos.
 * @param fallbackExtensions Lista de extensiones permitidas.
 * @returns `true` si es un tipo permitido.
 */
function matchesAllowedMime(file: File, allowed: Set<string>, fallbackExtensions: readonly string[]): boolean {
    // Nota: validación puramente UX; el backend vuelve a validar firma/MIME reales.
    const mime = file.type?.toLowerCase() ?? '';
    if (mime && allowed.has(mime)) {
        return true;
    }

    const extension = getExtensionFromName(file.name);
    if (!extension) {
        return false;
    }

    return fallbackExtensions.some((value) => value === extension);
}

/**
 * Hook de Vue para gestionar la subida y eliminación de avatares de usuario.
 *
 * Proporciona funciones para validar, subir y eliminar avatares, con manejo de estado,
 * validación local (tamaño, dimensiones, MIME real), cancelación de operaciones,
 * actualización automática de la UI y manejo de errores.
 *
 * @param options Opciones para personalizar el comportamiento del hook.
 * @returns Un objeto con estados reactivos, funciones y utilidades para gestionar el avatar.
 *
 * @example
 * // En un componente Vue
 * const { uploadAvatar, isUploading, resolveAvatarUrl } = useAvatarUpload();
 *
 * const handleFileChange = async (event) => {
 *   const file = event.target.files[0];
 *   try {
 *     await uploadAvatar(file);
 *     console.log('Avatar subido exitosamente!');
 *   } catch (error) {
 *     console.error('Error al subir:', error.message);
 *   }
 * };
 */
export function useAvatarUpload(options: UseAvatarUploadOptions = {}) {
    const { t, locale } = useI18n(); // Para traducciones y formato de números

    // Fusiona las opciones con los valores por defecto
    const mergedOptions = {
        uploadRoute: options.uploadRoute ?? DEFAULT_OPTIONS.uploadRoute,
        deleteRoute: options.deleteRoute ?? DEFAULT_OPTIONS.deleteRoute,
        refreshAuthOnSuccess: options.refreshAuthOnSuccess ?? DEFAULT_OPTIONS.refreshAuthOnSuccess,
    };

    // Fusiona las restricciones de validación
    const mergedConstraints: AvatarValidationConstraints = {
        ...DEFAULT_CONSTRAINTS,
        ...options.constraints,
    };

    // Prepara conjuntos y listas para validación rápida
    const allowedMimeSet = new Set(mergedConstraints.allowedMimeTypes.map((mime) => mime.toLowerCase()));
    const allowedExtensions = mergedConstraints.allowedMimeTypes.map((mime) => mime.split('/').pop() ?? '').filter((value) => value !== '');
    const allowedSummary = buildAllowedSummary(mergedConstraints.allowedMimeTypes);
    // String para el atributo `accept` del input de archivo
    const acceptAttribute = mergedConstraints.allowedMimeTypes.join(',');

    // Accede a los props de la página y config de Ziggy
    const page = usePage<AppPageProps>();
    const ziggyConfig = computed<Config | null>(() => {
        const config = page.props.ziggy as Config | undefined;
        return config ?? null;
    });
    const currentOrigin = computed<string | null>(() => {
        const ziggyLocation = page.props.ziggy?.location ?? null;
        return resolveCurrentOrigin(ziggyLocation);
    });

    // Computado para el usuario autenticado
    const authUser = computed<User | null>(() => {
        return (page.props.auth?.user as User | undefined) ?? null;
    });

    // Ref para invalidar la caché de la imagen de avatar (previene cacheo del navegador)
    const avatarCacheBuster = ref<number>(0);

    // Obtiene la URL "cruda" del avatar del usuario desde los props
    const getRawAvatarValue = (user: User | null | undefined): string | null => {
        if (!user) {
            return null;
        }

        const candidates = [user.avatar_thumb_url, user.avatar_url, user.avatar];

        for (const candidate of candidates) {
            if (typeof candidate !== 'string') {
                continue;
            }

            const trimmed = candidate.trim();
            if (trimmed !== '') {
                return trimmed;
            }
        }

        return null;
    };

    // Resuelve la URL final del avatar, aplicando el cache buster si es necesario
    const resolveAvatarUrl = (user: User | null | undefined): string | null => {
        const base = getRawAvatarValue(user);
        if (!base) {
            return null;
        }

        const normalizedBase = normalizeAssetHost(base, currentOrigin.value);
        if (avatarCacheBuster.value <= 0) {
            return normalizedBase;
        }

        // Añade el parámetro de cache buster
        const separator = normalizedBase.includes('?') ? '&' : '?';
        return `${normalizedBase}${separator}${CACHE_BUSTER_PARAM}=${avatarCacheBuster.value}`;
    };

    // Estados reactivos para el UI
    const isUploading = ref(false); // Indica si una subida está en progreso
    const isDeleting = ref(false); // Indica si una eliminación está en progreso
    const uploadProgress = ref<number | null>(null); // Progreso de la subida (0-100) o null
    const errors = ref<ErrorBag>({}); // Errores de campo devueltos por el backend
    const generalError = ref<string | null>(null); // Error general para mostrar al usuario
    const currentUpload = ref<OngoingUpload | null>(null); // Subida en curso

    // Computado para saber si el usuario tiene un avatar actualmente
    const hasAvatar = computed<boolean>(() => getRawAvatarValue(authUser.value) !== null);

    // Limpia los errores actuales
    const resetErrors = () => {
        errors.value = {};
        generalError.value = null;
    };

    // Normaliza los errores recibidos del backend (pueden ser string o array)
    const normalizeErrors = (errorBag: Record<string, string | string[]>): ErrorBag => {
        const result: ErrorBag = {};
        Object.entries(errorBag).forEach(([field, message]) => {
            if (!message) {
                return;
            }

            if (Array.isArray(message)) {
                result[field] = message.map((value) => value.toString());
                return;
            }

            result[field] = [message.toString()];
        });
        return result;
    };

    // Establece un error específico para el campo avatar
    const setAvatarError = (message: string) => {
        errors.value = { avatar: [message] };
        generalError.value = message;
    };

    // Refresca los datos de autenticación en la página para reflejar el nuevo avatar
    const refreshAuth = () => {
        if (!mergedOptions.refreshAuthOnSuccess) {
            return;
        }

        try {
            router.reload({
                only: ['auth', 'flash'], // Solo recarga los props necesarios
            });
        } catch (error) {
            console.warn('Failed to reload auth data after avatar operation.', error);
        }
    };

    // Abortar la subida actual (por ejemplo, si el usuario cancela)
    const abortCurrentUpload = (options?: { silent?: boolean }) => {
        const ongoing = currentUpload.value;
        if (!ongoing) {
            return;
        }

        if (ongoing.controller && !ongoing.controller.signal.aborted) {
            ongoing.controller.abort(); // Aborta la petición con AbortController
        } else {
            ongoing.cancelRequest?.(); // Aborta con el token de cancelación de Inertia
        }

        currentUpload.value = null;
        isUploading.value = false; // Limpia también los estados de UI
        uploadProgress.value = null;

        if (!options?.silent) {
            const message = t('profile.avatar_error_cancelled_upload');
            setAvatarError(message);
        }
    };

    // Abortar subida en desmontaje del componente para evitar fugas de memoria
    onBeforeUnmount(() => {
        abortCurrentUpload({ silent: true });
    });

    // Valida un archivo de imagen localmente antes de subirlo
    const validateAvatarFile = async (file: File): Promise<UploadValidationResult> => {
        const sanitizedName = sanitizeFilename(file.name);

        if (!(file instanceof File)) {
            const message = t('profile.avatar_error_required');
            setAvatarError(message);
            throw new Error(message);
        }

        if (file.size <= 0) {
            const message = t('profile.avatar_error_empty');
            setAvatarError(message);
            throw new Error(message);
        }

        if (file.size > mergedConstraints.maxBytes) {
            const maxFormatted = formatBytes(mergedConstraints.maxBytes, locale.value);
            const message = t('profile.avatar_error_size', { max: maxFormatted });
            setAvatarError(message);
            throw new Error(message);
        }

        if (!matchesAllowedMime(file, allowedMimeSet, allowedExtensions)) {
            const message = t('profile.avatar_error_type', { allowed: allowedSummary });
            setAvatarError(message);
            throw new Error(message);
        }

        // Valida el tipo MIME real inspeccionando los bytes del archivo
        const detectedMime = await detectMimeType(file);
        if (detectedMime && !allowedMimeSet.has(detectedMime)) {
            const message = t('profile.avatar_error_type', { allowed: allowedSummary });
            setAvatarError(message);
            throw new Error(message);
        }

        let metadata: { width: number; height: number };
        try {
            metadata = await readImageDimensions(file);
        } catch (error) {
            const message = t('profile.avatar_error_invalid_image');
            setAvatarError(message);
            throw error instanceof Error ? error : new Error(message);
        }

        if (metadata.width < mergedConstraints.minDimension || metadata.height < mergedConstraints.minDimension) {
            const message = t('profile.avatar_error_dimensions', { min: mergedConstraints.minDimension });
            setAvatarError(message);
            throw new Error(message);
        }

        const megapixels = (metadata.width * metadata.height) / 1_000_000;
        // Añade una pequeña tolerancia (0.001) para evitar errores por redondeo
        if (megapixels > mergedConstraints.maxMegapixels + 0.001) {
            const message = t('profile.avatar_error_megapixels', { max: mergedConstraints.maxMegapixels });
            setAvatarError(message);
            throw new Error(message);
        }

        return {
            width: metadata.width,
            height: metadata.height,
            bytes: file.size,
            sanitizedName,
        };
    };

    /**
     * Sube un archivo de imagen como avatar.
     *
     * Realiza validación local, envía el archivo al servidor, maneja el progreso
     * y actualiza la interfaz según el resultado.
     *
     * @param file El archivo de imagen a subir.
     * @returns Una promesa que resuelve con un payload de éxito o se rechaza con un error.
     *
     * @example
     * // Resultado exitoso
     * { filename: "foto_perfil.jpg", width: 400, height: 400, bytes: 123456 }
     *
     * // Resultado de error (tamaño)
     * Error: El tamaño del archivo excede el límite de 20.0 MB.
     *
     * // Resultado de error (red)
     * Error: Error de red o de redirección.
     */
    const uploadAvatar = async (file: File | null | undefined): Promise<AvatarUploadSuccessPayload> => {
        resetErrors();

        if (!(file instanceof File)) {
            const message = t('profile.avatar_error_required');
            setAvatarError(message);
            return Promise.reject(new Error(message));
        }

        let validation: UploadValidationResult;
        try {
            validation = await validateAvatarFile(file);
        } catch (error) {
            return Promise.reject(error instanceof Error ? error : new Error(String(error)));
        }

        let uploadUrl: string;
        try {
            uploadUrl = resolveRoute(mergedOptions.uploadRoute, ziggyConfig.value);
        } catch (error) {
            const message = t('profile.avatar_error_route_missing');
            setAvatarError(message);
            return Promise.reject(error instanceof Error ? error : new Error(message));
        }

        abortCurrentUpload({ silent: true }); // Cancela cualquier subida anterior

        const controller = SUPPORTS_ABORT ? new AbortController() : null;

        return new Promise<AvatarUploadSuccessPayload>((resolve, reject) => {
            let removeAbortListener: (() => void) | null = null;
            let resolution: 'pending' | 'resolved' | 'rejected' = 'pending';

            // Finaliza la promesa una sola vez
            const finalize = (outcome: 'resolved' | 'rejected', callback: () => void) => {
                if (resolution !== 'pending') {
                    return;
                }

                resolution = outcome;
                removeAbortListener?.(); // Limpia listeners de abort
                removeAbortListener = null;
                currentUpload.value = null; // Limpia la subida en curso
                callback();
            };

            const ongoing: OngoingUpload = { controller };
            currentUpload.value = ongoing;

            // Realiza la petición POST con Inertia
            router.post(
                uploadUrl,
                { _method: 'PATCH', avatar: file },
                {
                    forceFormData: true, // Asegura que se envíe como multipart/form-data
                    preserveScroll: true, // Mantiene la posición del scroll
                    onCancelToken: ({ cancel }) => {
                        ongoing.cancelRequest = cancel; // Guarda la función de cancelación

                        if (controller) {
                            // Añade un listener para abortar con AbortController
                            const handleAbort = () => cancel();
                            controller.signal.addEventListener('abort', handleAbort, { once: true });
                            removeAbortListener = () => controller.signal.removeEventListener('abort', handleAbort);
                        }
                    },
                    onStart: () => {
                        isUploading.value = true;
                        uploadProgress.value = 0;
                    },
                    onProgress: (progress) => {
                        uploadProgress.value = progress?.percentage ?? null;
                    },
                    onError: (errorBag) => {
                        const normalized = normalizeErrors(errorBag);
                        const fallback = t('profile.avatar_error_generic');
                        const message = normalized.avatar?.[0] ?? fallback;
                        setAvatarError(message);
                        finalize('rejected', () => reject(new Error(message)));
                    },
                    onCancel: () => {
                        const message = t('profile.avatar_error_cancelled_upload');
                        setAvatarError(message);
                        finalize('rejected', () => reject(new Error(message)));
                    },
                    onSuccess: () => {
                        resetErrors();
                        refreshAuth(); // Actualiza los datos de auth para mostrar el nuevo avatar
                        // Añade un valor aleatorio al cache buster para forzar recarga
                        avatarCacheBuster.value = Date.now() + Math.floor(Math.random() * 1000);
                        const payload: AvatarUploadSuccessPayload = {
                            filename: validation.sanitizedName,
                            width: validation.width,
                            height: validation.height,
                            bytes: validation.bytes,
                        };
                        finalize('resolved', () => resolve(payload));
                    },
                    onFinish: (visit) => {
                        removeAbortListener?.(); // Asegura limpieza de listeners
                        removeAbortListener = null;
                        currentUpload.value = null;
                        isUploading.value = false;
                        uploadProgress.value = null;

                        if (resolution !== 'pending') {
                            return; // Ya se resolvió
                        }

                        // Error si la visita no se completó ni se canceló
                        const message = !visit.completed && !visit.cancelled ? t('profile.avatar_error_network') : t('profile.avatar_error_generic');

                        setAvatarError(message);
                        finalize('rejected', () => reject(new Error(message)));
                    },
                },
            );
        });
    };

    /**
     * Elimina el avatar actual del usuario.
     *
     * @returns Una promesa que se resuelve cuando la eliminación es exitosa o se rechaza con un error.
     *
     * @example
     * // Resultado exitoso
     * // (no devuelve valor, pero actualiza el estado y la UI)
     *
     * // Resultado de error (red)
     * Error: Error de red o de redirección.
     */
    const removeAvatar = async (): Promise<void> => {
        resetErrors();

        abortCurrentUpload({ silent: true }); // Cancela cualquier subida en curso

        let deleteUrl: string;
        try {
            deleteUrl = resolveRoute(mergedOptions.deleteRoute, ziggyConfig.value);
        } catch (error) {
            const message = t('profile.avatar_error_route_missing');
            setAvatarError(message);
            return Promise.reject(error instanceof Error ? error : new Error(message));
        }

        return new Promise<void>((resolve, reject) => {
            let resolution: 'pending' | 'resolved' | 'rejected' = 'pending';

            // Finaliza la promesa una sola vez
            const finalize = (outcome: 'resolved' | 'rejected', callback: () => void) => {
                if (resolution !== 'pending') {
                    return;
                }

                resolution = outcome;
                callback();
            };

            // Realiza la petición DELETE con Inertia
            router.visit(deleteUrl, {
                method: 'delete',
                preserveScroll: true,
                onStart: () => {
                    isDeleting.value = true;
                },
                onError: (errorBag) => {
                    const normalized = normalizeErrors(errorBag);
                    const fallback = t('profile.avatar_error_remove') as string;
                    const message = normalized.avatar?.[0] ?? fallback;
                    setAvatarError(message);
                    finalize('rejected', () => reject(new Error(message)));
                },
                onCancel: () => {
                    const message = t('profile.avatar_error_cancelled_delete');
                    setAvatarError(message);
                    finalize('rejected', () => reject(new Error(message)));
                },
                onSuccess: () => {
                    resetErrors();
                    refreshAuth(); // Actualiza los datos de auth
                    // Añade un valor aleatorio al cache buster
                    avatarCacheBuster.value = Date.now() + Math.floor(Math.random() * 1000);
                    finalize('resolved', () => resolve());
                },
                onFinish: (visit) => {
                    isDeleting.value = false;

                    if (resolution !== 'pending') {
                        return; // Ya se resolvió
                    }

                    if (!visit.completed && !visit.cancelled) {
                        const message = t('profile.avatar_error_network');
                        setAvatarError(message);
                        finalize('rejected', () => reject(new Error(message)));
                        return;
                    }

                    const message = t('profile.avatar_error_generic');
                    setAvatarError(message);
                    finalize('rejected', () => reject(new Error(message)));
                },
            });
        });
    };

    return {
        authUser, // Computado: usuario actual
        hasAvatar, // Computado: si tiene avatar
        isUploading, // Ref: estado de subida
        isDeleting, // Ref: estado de eliminación
        uploadProgress, // Ref: progreso (0-100) o null
        errors, // Ref: errores de campo
        generalError, // Ref: error general
        uploadAvatar, // Fn: subir avatar
        removeAvatar, // Fn: eliminar avatar
        resetErrors, // Fn: limpiar errores
        resolveAvatarUrl, // Fn: obtener URL del avatar con cache buster
        avatarCacheBuster, // Ref: valor para invalidar cache
        cancelUpload: abortCurrentUpload, // Fn: cancelar subida
        constraints: mergedConstraints, // Obj: restricciones actuales
        allowedMimeSummary: allowedSummary, // String: resumen de tipos permitidos ("JPEG, PNG, ...")
        formatBytesLabel: (bytes: number) => formatBytes(bytes, locale.value), // Fn: formatea bytes
        acceptMimeTypes: acceptAttribute, // String: para el attr `accept` del input HTML
    };
}
