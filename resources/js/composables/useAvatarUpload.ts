import { router, usePage } from '@inertiajs/vue3'; // Inertia router + page props (Ej. router.reload({ only: ['auth'] }))
import { computed, onBeforeUnmount, ref } from 'vue'; // Reactividad Vue (Ej. ref(false) -> { value: false })
import { useI18n } from 'vue-i18n'; // i18n (Ej. t('key') -> "Texto traducido")
import { notify } from '@/plugins/toaster-plugin'; // Toasts centralizados (notify.error('X') -> estilos consistentes)
import type { Config } from 'ziggy-js'; // Tipo config Ziggy (Ej. Config.location -> "https://app.test")
import { route } from 'ziggy-js'; // Resolución de rutas Ziggy (Ej. route('home') -> "/")

import { sendFormData, type NormalizedError } from '@/lib/http/xhrFormData'; // XHR helper (Ej. sendFormData(...) -> { data, status })
import type { AppPageProps, User } from '@/types'; // Tipos app (Ej. User.email -> "a@b.com")
import { useAvatarState } from '@/composables/useAvatarState';

// Tipo para los errores que devuelve el backend (Ej. { avatar: ["Mensaje"] })
type ErrorBag = Record<string, string[]>;

// Restricciones para la validación del archivo de avatar (UX; el backend valida también)
interface AvatarValidationConstraints {
    maxBytes: number; // Tamaño máximo en bytes (Ej. 25 * 1024 * 1024)
    minDimension: number; // Dimensión mínima en píxeles (Ej. 128)
    maxMegapixels: number; // Límite de megapíxeles (Ej. 48)
    allowedMimeTypes: readonly string[]; // Tipos MIME permitidos (Ej. ['image/jpeg', 'image/png'])
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
    width: number; // Ej. 400
    height: number; // Ej. 400
    bytes: number; // Ej. 123456
    sanitizedName: string; // Ej. "mi_avatar.png"
}

// Representa una subida en curso que puede cancelarse
interface OngoingUpload {
    controller: AbortController | null; // Ej. new AbortController()
}

// Datos devueltos tras una subida exitosa
export interface AvatarUploadSuccessPayload {
    filename: string; // Ej. "mi_avatar.png"
    width: number; // Ej. 400
    height: number; // Ej. 400
    bytes: number; // Ej. 123456
}

// Etiquetas para mostrar los tipos MIME en mensajes de error
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
const SUPPORTS_ABORT = typeof AbortController !== 'undefined'; // Ej. true en browsers modernos
const FALLBACK_FILENAME = 'avatar'; // Ej. "avatar"
const CACHE_BUSTER_PARAM = '_cb'; // Ej. "/avatar.png?_cb=123"
const IMAGE_READ_TIMEOUT_MS = 10_000; // 10s
const LOCAL_HOSTNAMES = new Set(['localhost', '127.0.0.1', '0.0.0.0']); // Hosts típicos dev

/**
 * Verifica si una cadena es una URL absoluta (http:// o https://).
 * @example isAbsoluteUrl('https://x.com') -> true
 */
function isAbsoluteUrl(target: string): boolean {
    return /^https?:\/\//i.test(target);
}

/**
 * Resuelve una ruta de Ziggy o una URL absoluta.
 * @example resolveRoute('settings.avatar.update', config) -> '/settings/avatar'
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
 * @example sanitizeFilename('my avatar <script>.jpg') -> 'my avatar .jpg'
 */
function sanitizeFilename(value: string | undefined): string {
    if (!value || typeof value !== 'string') {
        return FALLBACK_FILENAME;
    }

    const cleaned = value
        .normalize('NFKC')
        .replace(/[^\p{L}\p{N}_. -]+/gu, '')
        .trim()
        .slice(0, 150);

    return cleaned.length > 0 ? cleaned : FALLBACK_FILENAME;
}

/**
 * Convierte un tamaño en bytes a una cadena legible (MB).
 * @example formatBytes(2560000, 'es') -> "2,4 MB" (según locale)
 */
function formatBytes(bytes: number, locale: string): string {
    const megabytes = bytes / (1024 * 1024);
    const formatter = new Intl.NumberFormat(locale, {
        maximumFractionDigits: megabytes >= 10 ? 0 : 1,
    });

    return `${formatter.format(megabytes)} MB`;
}

/**
 * Lee las dimensiones reales de un archivo de imagen con un timeout.
 * @example readImageDimensions(file) -> Promise<{ width: 400, height: 400 }>
 */
async function readImageDimensions(file: File): Promise<{ width: number; height: number }> {
    if (typeof window !== 'undefined' && 'createImageBitmap' in window && typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file);
            const dimensions = { width: bitmap.width, height: bitmap.height };
            if (typeof bitmap.close === 'function') {
                bitmap.close();
            }
            return dimensions;
        } catch (error) {
            // Nota: warning solo informativo, cae al fallback Image()
            console.warn('createImageBitmap failed, falling back to Image()', error);
        }
    }

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

            URL.revokeObjectURL(objectUrl);

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
 * @example resolveCurrentOrigin('https://app.test') -> 'https://app.test'
 */
function resolveCurrentOrigin(locationCandidate: string | null | undefined): string | null {
    const candidates = [locationCandidate];

    if (typeof window !== 'undefined' && window.location?.origin) {
        candidates.push(window.location.origin);
    }

    for (const candidate of candidates) {
        if (!candidate) continue;

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
 * @example normalizeAssetHost('http://localhost/storage/a.png', 'https://app.test') -> 'https://app.test/storage/a.png'
 */
function normalizeAssetHost(url: string, preferredOrigin: string | null): string {
    if (!url) return url;

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
 * @example detectMimeType(fileJPEG) -> "image/jpeg"
 */
async function detectMimeType(file: File): Promise<string | null> {
    try {
        const buffer = await file.slice(0, 12).arrayBuffer();
        const bytes = new Uint8Array(buffer);

        // JPEG (FF D8 FF)
        if (bytes.length >= 3 && bytes[0] === 0xff && bytes[1] === 0xd8 && bytes[2] === 0xff) {
            return 'image/jpeg';
        }

        // PNG (89 50 4E 47)
        if (bytes.length >= 8 && bytes[0] === 0x89 && bytes[1] === 0x50 && bytes[2] === 0x4e && bytes[3] === 0x47) {
            return 'image/png';
        }

        // GIF (47 49 46)
        if (bytes.length >= 3 && bytes[0] === 0x47 && bytes[1] === 0x49 && bytes[2] === 0x46) {
            return 'image/gif';
        }

        // WebP (.... WEBP)
        if (bytes.length >= 12 && bytes[8] === 0x57 && bytes[9] === 0x45 && bytes[10] === 0x42 && bytes[11] === 0x50) {
            return 'image/webp';
        }

        // AVIF (ftypavif)
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
 * @example buildAllowedSummary(['image/jpeg','image/png']) -> "JPEG, PNG"
 */
function buildAllowedSummary(mimeTypes: readonly string[]): string {
    return mimeTypes.map((mime) => MIME_EXTENSION_LABELS[mime] ?? mime).join(', ');
}

/**
 * Extrae la extensión de un nombre de archivo.
 * @example getExtensionFromName('foto.png') -> "png"
 */
function getExtensionFromName(name: string | undefined): string | null {
    if (!name) return null;

    const match = name.split('.').pop();
    if (!match) return null;

    return match.trim().toLowerCase();
}

/**
 * Verifica si un archivo coincide con los tipos MIME o extensiones permitidas.
 * NOTA: validación UX; el backend valida firma/MIME reales.
 */
function matchesAllowedMime(file: File, allowed: Set<string>, fallbackExtensions: readonly string[]): boolean {
    const mime = file.type?.toLowerCase() ?? '';

    if (mime && allowed.has(mime)) {
        return true;
    }

    const extension = getExtensionFromName(file.name);
    if (!extension) return false;

    return fallbackExtensions.some((value) => value === extension);
}

/**
 * Hook de Vue para gestionar la subida y eliminación de avatares.
 *
 * Objetivos clave de este refactor:
 * - Unificar la forma de rechazar errores (status/aborted/name) con rejectWithMeta().
 * - Marcar fallos de configuración (Ziggy/route missing) con name='ConfigError'.
 * - Mantener 422/403/429 como “errores esperables” para que el caller pueda silenciar logs.
 * - Evitar “churn” de estados/errores: setAvatarError ahora MERGE y no pisa otros campos.
 */
export function useAvatarUpload(options: UseAvatarUploadOptions = {}) {
    const { t, locale } = useI18n(); // Ej. t('profile.title') -> "Perfil"

    // Fusiona opciones
    const mergedOptions = {
        uploadRoute: options.uploadRoute ?? DEFAULT_OPTIONS.uploadRoute,
        deleteRoute: options.deleteRoute ?? DEFAULT_OPTIONS.deleteRoute,
        refreshAuthOnSuccess: options.refreshAuthOnSuccess ?? DEFAULT_OPTIONS.refreshAuthOnSuccess,
    };

    // Fusiona restricciones
    const mergedConstraints: AvatarValidationConstraints = {
        ...DEFAULT_CONSTRAINTS,
        ...options.constraints,
    };

    // Conjuntos/listas para validación rápida
    const allowedMimeSet = new Set(mergedConstraints.allowedMimeTypes.map((mime) => mime.toLowerCase())); // Ej. Set("image/png")
    const allowedExtensions = mergedConstraints.allowedMimeTypes.map((mime) => mime.split('/').pop() ?? '').filter((value) => value !== ''); // Ej. ["jpeg","png",...]
    const allowedSummary = buildAllowedSummary(mergedConstraints.allowedMimeTypes); // Ej. "JPEG, PNG, WebP"
    const acceptAttribute = mergedConstraints.allowedMimeTypes.join(','); // Ej. "image/jpeg,image/png"

    // Page props + Ziggy
    const page = usePage<AppPageProps>(); // Ej. page.props.auth.user
    const { setAvatarOverride } = useAvatarState();
    const ziggyConfig = computed<Config | null>(() => {
        const config = page.props.ziggy as Config | undefined;
        return config ?? null;
    });
    const currentOrigin = computed<string | null>(() => {
        const ziggyLocation = page.props.ziggy?.location ?? null;
        return resolveCurrentOrigin(ziggyLocation);
    });

    // Usuario autenticado
    const authUser = computed<User | null>(() => (page.props.auth?.user as User | undefined) ?? null);

    // Cache buster para evitar que el navegador te sirva el avatar viejo
    const avatarCacheBuster = ref<number>(0);

    // Devuelve el “valor crudo” de avatar desde props
    const getRawAvatarValue = (user: User | null | undefined): string | null => {
        if (!user) return null;

        const candidates = [user.avatar_thumb_url, user.avatar_url, user.avatar];

        for (const candidate of candidates) {
            if (typeof candidate !== 'string') continue;

            const trimmed = candidate.trim();
            if (trimmed !== '') return trimmed;
        }

        return null;
    };

    // URL final del avatar (normaliza host + cache buster)
    const resolveAvatarUrl = (user: User | null | undefined): string | null => {
        const base = getRawAvatarValue(user);
        if (!base) return null;

        const normalizedBase = normalizeAssetHost(base, currentOrigin.value);

        if (avatarCacheBuster.value <= 0) {
            return normalizedBase;
        }

        const separator = normalizedBase.includes('?') ? '&' : '?';
        return `${normalizedBase}${separator}${CACHE_BUSTER_PARAM}=${avatarCacheBuster.value}`;
    };

    // Estados reactivos UI
    const isUploading = ref(false); // Ej. true mientras PATCH en curso
    const isDeleting = ref(false); // Ej. true mientras DELETE en curso
    const uploadProgress = ref<number | null>(null); // Ej. 0..100
    const errors = ref<ErrorBag>({}); // Ej. { avatar: ["..."] }
    const generalError = ref<string | null>(null); // Ej. "No se pudo..."
    const recentlySuccessful = ref<boolean>(false);
    const successMessage = ref<string | null>(null);
    const currentUpload = ref<OngoingUpload | null>(null); // Ej. { controller: AbortController }

    // Si hay avatar
    const hasAvatar = computed<boolean>(() => getRawAvatarValue(authUser.value) !== null);

    // Limpia errores
    const resetErrors = () => {
        errors.value = {};
        generalError.value = null;
    };

    // Normaliza bag de errores backend
    const normalizeErrors = (errorBag: Record<string, string | string[]>): ErrorBag => {
        const result: ErrorBag = {};

        Object.entries(errorBag).forEach(([field, message]) => {
            if (!message) return;

            if (Array.isArray(message)) {
                result[field] = message.map((value) => value.toString());
                return;
            }

            result[field] = [message.toString()];
        });

        return result;
    };

    /**
     * Setea error para el campo avatar SIN pisar otros campos ya presentes.
     * Motivo: si el backend te devuelve más cosas (otros campos), no los borras por accidente.
     * @example errors.value={name:["x"]} + setAvatarError("a") -> {name:["x"], avatar:["a"]}
     */
    const setAvatarError = (message: string) => {
        errors.value = { ...errors.value, avatar: [message] };
        generalError.value = message;
    };

    /**
     * Rechazo uniforme: el caller SIEMPRE recibe metadata.
     *
     * Convención de `name`:
     * - AbortError: cancelación explícita/AbortController
     * - ConfigError: fallo de configuración (Ziggy/route)
     * - ClientValidationError: fallo UX/local (archivo inválido antes de enviar)
     * - NetworkError: status 0 sin abort (CORS / offline / timeout)
     * - HttpError: resto de errores HTTP
     */
    const rejectWithMeta = (
        message: string,
        { status: metaStatus = 0, aborted = false, name = 'HttpError' }: { status?: number; aborted?: boolean; name?: string } = {},
    ) => {
        const ex: any = Object.assign(new Error(message), {
            status: metaStatus,
            aborted,
            name,
        });

        return Promise.reject(ex);
    };

    // Refresca auth en props (para que el avatar cambie sin hard reload)
    const refreshAuth = () => {
        if (!mergedOptions.refreshAuthOnSuccess) return;

        try {
            router.reload({ only: ['auth', 'flash'] });
        } catch (error) {
            console.warn('Failed to reload auth data after avatar operation.', error);
        }
    };

    // Abortar subida actual (el caller puede llamar cancelUpload())
    const abortCurrentUpload = (options?: { silent?: boolean }) => {
        const ongoing = currentUpload.value;
        if (!ongoing) return;

        if (ongoing.controller && !ongoing.controller.signal.aborted) {
            ongoing.controller.abort();
        }

        currentUpload.value = null;
        isUploading.value = false;
        uploadProgress.value = null;

        if (!options?.silent) {
            const message = t('profile.avatar_error_cancelled_upload');
            setAvatarError(message);
        }
    };

    // Helpers para extraer mensajes del backend / payloads raros
    const coerceMessage = (value: unknown): string | undefined => {
        if (typeof value === 'string' && value.trim() !== '') return value.trim();
        return undefined;
    };

    const pickAnyErrorMessage = (bag: Record<string, unknown> | undefined): string | undefined => {
        if (!bag || typeof bag !== 'object' || Array.isArray(bag)) return undefined;

        const firstKey = Object.keys(bag)[0];
        if (!firstKey) return undefined;

        const value = (bag as Record<string, unknown>)[firstKey];

        if (Array.isArray(value)) {
            return value.map(coerceMessage).find(Boolean);
        }

        return coerceMessage(value);
    };

    const extractPayloadMessage = (payload: unknown): string | undefined => {
        if (!payload) return undefined;

        const direct = coerceMessage(payload);
        if (direct) return direct;

        if (typeof payload === 'string') {
            try {
                const parsed = JSON.parse(payload);
                if (parsed && typeof parsed === 'object') {
                    const parsedMessage = extractPayloadMessage(parsed);
                    if (parsedMessage) return parsedMessage;
                }
            } catch {
                // No es JSON: seguimos
            }
        }

        if (typeof payload === 'object' && !Array.isArray(payload)) {
            const raw = payload as Record<string, unknown>;

            const message = coerceMessage(raw.message) ?? coerceMessage(raw.error);
            if (message) return message;

            if (raw.errors && typeof raw.errors === 'object' && !Array.isArray(raw.errors)) {
                const nestedError = pickAnyErrorMessage(raw.errors as Record<string, unknown>);
                if (nestedError) return nestedError;
            }

            if (raw.error && typeof raw.error === 'object') {
                const nested = extractPayloadMessage(raw.error);
                if (nested) return nested;
            }
        }

        return undefined;
    };

    const pickServerMessage = (err: NormalizedError): string | undefined => {
        if (err && typeof err === 'object') {
            const direct = coerceMessage(err.message) ?? extractPayloadMessage(err.data);
            if (direct) return direct;

            const statusMessage = coerceMessage(err.statusText);
            if (statusMessage) return statusMessage;
        }

        return undefined;
    };

    const pickErrorFromBag = (bag: ErrorBag, field = 'avatar'): string | undefined => {
        const value = bag[field];
        if (!Array.isArray(value)) return undefined;
        return value.map(coerceMessage).find(Boolean);
    };

    const sanitizeMessage = (message: string | undefined, fallback: string): string => {
        if (!message) return fallback;

        const trimmed = message.trim();

        // Filtro de mensajes genéricos para no mostrar basura al usuario
        if (/excepci[oó]n no controlada/i.test(trimmed) || /^uncontrolled exception/i.test(trimmed)) {
            return fallback;
        }

        return trimmed;
    };

    const resolveAvatarErrorMessage = (err: NormalizedError, normalized: ErrorBag, fallback: string): string => {
        const message =
            pickServerMessage(err) ??
            pickErrorFromBag(normalized) ??
            pickAnyErrorMessage(err.errors as Record<string, unknown> | undefined) ??
            coerceMessage(err.message) ??
            coerceMessage(err.statusText) ??
            fallback;

        return sanitizeMessage(message, fallback);
    };

    // Limpieza al desmontar
    onBeforeUnmount(() => {
        abortCurrentUpload({ silent: true });
    });

    // Validación local (UX)
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
     * Sube un archivo como avatar.
     * Devuelve payload de éxito o rechaza con error “status-aware” vía rejectWithMeta().
     */
    const uploadAvatar = async (file: File | null | undefined): Promise<AvatarUploadSuccessPayload> => {
        resetErrors();

        // Validación básica input
        if (!(file instanceof File)) {
            const message = t('profile.avatar_error_required');
            setAvatarError(message);
            return rejectWithMeta(message, { status: 0, name: 'ClientValidationError' });
        }

        // Validación UX (tamaño, dimensiones, mime, etc.)
        let validation: UploadValidationResult;

        try {
            validation = await validateAvatarFile(file);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            return rejectWithMeta(message, { status: 0, name: 'ClientValidationError' });
        }

        // Resolver URL (Ziggy/absolute)
        let uploadUrl: string;

        try {
            uploadUrl = resolveRoute(mergedOptions.uploadRoute, ziggyConfig.value);
        } catch {
            const message = t('profile.avatar_error_route_missing');
            setAvatarError(message);
            // Aquí es donde cambias el name: ConfigError (lo que preguntabas)
            return rejectWithMeta(message, { status: 0, name: 'ConfigError' });
        }

        // Cancela cualquier subida previa
        abortCurrentUpload({ silent: true });

        // Prepara abort controller
        const controller = SUPPORTS_ABORT ? new AbortController() : null;
        currentUpload.value = { controller };

        // FormData
        const formData = new FormData();
        formData.append('avatar', file);

        // Estados UI
        isUploading.value = true;
        uploadProgress.value = 0;

        try {
            const response = await sendFormData({
                url: uploadUrl,
                method: 'PATCH',
                formData,
                signal: controller?.signal,
                onProgress: (percent) => {
                    uploadProgress.value = percent;
                },
            });

        resetErrors();
        setAvatarOverride(undefined);
        refreshAuth();

        // Cambia el cache buster para forzar refresh del avatar
        avatarCacheBuster.value = Date.now() + Math.floor(Math.random() * 1000);

        // Éxito
        const payload = (response?.data ?? {}) as Record<string, unknown>;
        const message = typeof payload.message === 'string' ? payload.message : 'Avatar actualizado correctamente.';
        // Usamos el mismo estilo de toast “event” que el guardado de perfil para coherencia visual.
        notify.event(message);
        successMessage.value = message;
        recentlySuccessful.value = true;
        setTimeout(() => (recentlySuccessful.value = false), 3000);

        return {
            filename: validation.sanitizedName,
            width: validation.width,
            height: validation.height,
            bytes: validation.bytes,
            };
        } catch (error) {
            const err = error as NormalizedError;
            const status = err.status || 0;
            const normalized = normalizeErrors((err.errors as Record<string, string | string[]> | undefined) ?? {});
            const fallback = status === 0 ? t('profile.avatar_error_network') : t('profile.avatar_error_generic');

            // Detecta abort/cancel (no toast)
            const isAborted = controller?.signal?.aborted === true || (err as { aborted?: boolean })?.aborted === true || err?.name === 'AbortError';

            if (isAborted) {
                const message = t('profile.avatar_error_cancelled_upload');
                setAvatarError(message);
                return rejectWithMeta(message, { status: 0, aborted: true, name: 'AbortError' });
            }

            // 422 (validación backend): toast error + bag + metadata
            if (status === 422) {
                errors.value = normalized;
                const message = resolveAvatarErrorMessage(err, normalized, fallback);
                setAvatarError(message);
                notify.error(message);
                return rejectWithMeta(message, { status: 422, name: 'HttpError' });
            }

            // 403 (forbidden): toast error + metadata
            if (status === 403) {
                const message = resolveAvatarErrorMessage(err, normalized, fallback);
                setAvatarError(message);
                notify.error(message);
                return rejectWithMeta(message, { status: 403, name: 'HttpError' });
            }

            // 429 (rate limit): toast warning + metadata
            if (status === 429) {
                const retry = err.retryAfter ?? 0;
                const base = resolveAvatarErrorMessage(err, normalized, fallback);
                const message = retry > 0 ? `${base} (retry in ${retry}s)` : base;
                setAvatarError(message);
                notify.warning(message);
                return rejectWithMeta(message, { status: 429, name: 'HttpError' });
            }

            // Default: error genérico (red / 5xx / etc.)
            const message = resolveAvatarErrorMessage(err, normalized, fallback);
            setAvatarError(message);
            notify.error(message);

            // Si es status 0, lo marcamos como NetworkError para que el caller lo trate distinto si quiere
            const name = status === 0 ? 'NetworkError' : 'HttpError';
            return rejectWithMeta(message, { status, name });
        } finally {
            // Finalizer solo resetea estado local de subida
            currentUpload.value = null;
            isUploading.value = false;
            uploadProgress.value = null;
        }
    };

    /**
     * Elimina el avatar actual.
     * Rechazos también “status-aware” vía rejectWithMeta() para consistencia con uploadAvatar().
     */
    const removeAvatar = async (): Promise<void> => {
        resetErrors();

        // Cancela cualquier subida en curso
        abortCurrentUpload({ silent: true });

        // Resolver URL
        let deleteUrl: string;

        try {
            deleteUrl = resolveRoute(mergedOptions.deleteRoute, ziggyConfig.value);
        } catch {
            const message = t('profile.avatar_error_route_missing');
            setAvatarError(message);
            // Misma convención: ConfigError
            return rejectWithMeta(message, { status: 0, name: 'ConfigError' });
        }

        const controller = SUPPORTS_ABORT ? new AbortController() : null;
        isDeleting.value = true;

        try {
            const response = await sendFormData({
                url: deleteUrl,
                method: 'DELETE',
                formData: new FormData(),
                signal: controller?.signal,
            });

        resetErrors();
        setAvatarOverride(null);
        refreshAuth();

        avatarCacheBuster.value = Date.now() + Math.floor(Math.random() * 1000);

        const payload = (response?.data ?? {}) as Record<string, unknown>;
        const message = typeof payload.message === 'string' ? payload.message : 'Avatar eliminado correctamente.';
        // Misma línea visual que el resto de toasts de perfil.
        notify.event(message);
        successMessage.value = message;
        recentlySuccessful.value = true;
        setTimeout(() => (recentlySuccessful.value = false), 3000);

            return;
        } catch (error) {
            const err = error as NormalizedError;
            const status = err.status || 0;
            const normalized = normalizeErrors((err.errors as Record<string, string | string[]> | undefined) ?? {});
            const fallback = status === 0 ? t('profile.avatar_error_network') : t('profile.avatar_error_generic');

            const isAborted = controller?.signal?.aborted === true || (err as { aborted?: boolean })?.aborted === true || err?.name === 'AbortError';

            if (isAborted) {
                const message = t('profile.avatar_error_cancelled_upload');
                setAvatarError(message);
                return rejectWithMeta(message, { status: 0, aborted: true, name: 'AbortError' });
            }

            if (status === 422) {
                errors.value = normalized;
                const message = resolveAvatarErrorMessage(err, normalized, fallback);
                setAvatarError(message);
                notify.error(message);
                return rejectWithMeta(message, { status: 422, name: 'HttpError' });
            }

            if (status === 403) {
                const message = resolveAvatarErrorMessage(err, normalized, fallback);
                setAvatarError(message);
                notify.error(message);
                return rejectWithMeta(message, { status: 403, name: 'HttpError' });
            }

            if (status === 429) {
                const retry = err.retryAfter ?? 0;
                const base = resolveAvatarErrorMessage(err, normalized, fallback);
                const message = retry > 0 ? `${base} (retry in ${retry}s)` : base;
                setAvatarError(message);
                notify.warning(message);
                return rejectWithMeta(message, { status: 429, name: 'HttpError' });
            }

            const message = resolveAvatarErrorMessage(err, normalized, fallback);
            setAvatarError(message);
            notify.error(message);

            const name = status === 0 ? 'NetworkError' : 'HttpError';
            return rejectWithMeta(message, { status, name });
        } finally {
            isDeleting.value = false;
        }
    };

    return {
        authUser, // Ej. { id: 1, name: "Johan" }
        hasAvatar, // Ej. true
        isUploading, // Ej. true/false
        isDeleting, // Ej. true/false
        uploadProgress, // Ej. 0..100 o null
        errors, // Ej. { avatar: ["..."] }
        generalError, // Ej. "..."
        recentlySuccessful, // Flag para mostrar mensaje inline de éxito
        successMessage, // Último mensaje de éxito
        uploadAvatar, // Fn: subir avatar
        removeAvatar, // Fn: eliminar avatar
        resetErrors, // Fn: limpiar errores
        resolveAvatarUrl, // Fn: URL del avatar
        avatarCacheBuster, // Ej. 1700000000000
        cancelUpload: abortCurrentUpload, // Fn: cancelar subida
        constraints: mergedConstraints, // Ej. { maxBytes: ..., allowedMimeTypes: ... }
        allowedMimeSummary: allowedSummary, // Ej. "JPEG, PNG, WebP..."
        formatBytesLabel: (bytes: number) => formatBytes(bytes, locale.value), // Ej. formatBytesLabel(1048576) -> "1 MB"
        acceptMimeTypes: acceptAttribute, // Ej. "image/jpeg,image/png,..."
    };
}
