import { notify } from '@/plugins/toaster-plugin';
import { router, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Config } from 'ziggy-js';
import { route } from 'ziggy-js';

import { sendFormData, type NormalizedError } from '@/lib/http/xhrFormData';
import type { AppPageProps, User } from '@/types';

// ============================================================================
// TYPES
// ============================================================================

type ErrorBag = Record<string, string[]>;

interface AvatarValidationConstraints {
    maxBytes: number;
    minDimension: number;
    maxMegapixels: number;
    allowedMimeTypes: readonly string[];
}

interface UseAvatarUploadOptions {
    uploadRoute?: string;
    deleteRoute?: string;
    refreshAuthOnSuccess?: boolean;
    constraints?: Partial<AvatarValidationConstraints>;
}

interface UploadValidationResult {
    width: number;
    height: number;
    bytes: number;
    sanitizedName: string;
}

interface OngoingUpload {
    controller: AbortController | null;
}

export interface AvatarUploadSuccessPayload {
    filename: string;
    width: number;
    height: number;
    bytes: number;
}

type AvatarOverride = {
    avatar_url?: string | null;
    avatar_thumb_url?: string | null;
    avatar_version?: string | number | null;
};

interface RejectionMeta {
    status?: number;
    aborted?: boolean;
    name?: string;
    toastShown?: boolean;
}

// ============================================================================
// CONSTANTS
// ============================================================================

export const MIME_EXTENSION_LABELS: Record<string, string> = {
    'image/jpeg': 'JPEG',
    'image/png': 'PNG',
    'image/gif': 'GIF',
    'image/webp': 'WebP',
    'image/avif': 'AVIF',
};

const DEFAULT_OPTIONS = {
    uploadRoute: 'settings.avatar.update',
    deleteRoute: 'settings.avatar.destroy',
    refreshAuthOnSuccess: true,
} as const satisfies Omit<Required<UseAvatarUploadOptions>, 'constraints'>;

const DEFAULT_CONSTRAINTS: AvatarValidationConstraints = {
    maxBytes: 25 * 1024 * 1024, // 25MB
    minDimension: 128,
    maxMegapixels: 48,
    allowedMimeTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'],
};

const SUPPORTS_ABORT = typeof AbortController !== 'undefined';
const FALLBACK_FILENAME = 'avatar';
const CACHE_BUSTER_PARAM = '_cb';
const IMAGE_READ_TIMEOUT_MS = 10_000;
const LOCAL_HOSTNAMES = new Set(['localhost', '127.0.0.1', '0.0.0.0']);
const SUCCESS_MESSAGE_DURATION = 3000;

// Magic numbers para detección de MIME
const MAGIC_NUMBERS = {
    JPEG: [0xff, 0xd8, 0xff],
    PNG: [0x89, 0x50, 0x4e, 0x47],
    GIF: [0x47, 0x49, 0x46],
    WEBP: { offset: 8, bytes: [0x57, 0x45, 0x42, 0x50] },
    AVIF: { offset: 4, bytes: [0x66, 0x74, 0x79, 0x70, 0x61, 0x76, 0x69, 0x66] },
} as const;

// ============================================================================
// UTILITY FUNCTIONS
// ============================================================================

function isAbsoluteUrl(target: string): boolean {
    return /^https?:\/\//i.test(target);
}

function resolveRoute(target: string, ziggyConfig: Config | null): string {
    if (!target || typeof target !== 'string') {
        throw new Error('Route name or URL must be a non-empty string.');
    }

    if (target.startsWith('/') || isAbsoluteUrl(target)) {
        return target;
    }

    if (!ziggyConfig) {
        throw new Error('Ziggy configuration is unavailable in page props.');
    }

    return route(target, undefined, false, ziggyConfig);
}

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

function formatBytes(bytes: number, locale: string): string {
    const megabytes = bytes / (1024 * 1024);
    const formatter = new Intl.NumberFormat(locale, {
        maximumFractionDigits: megabytes >= 10 ? 0 : 1,
    });

    return `${formatter.format(megabytes)} MB`;
}

async function readImageDimensions(file: File): Promise<{ width: number; height: number }> {
    // Intenta usar createImageBitmap (más rápido)
    if (typeof window !== 'undefined' && 'createImageBitmap' in window && typeof createImageBitmap === 'function') {
        try {
            const bitmap = await createImageBitmap(file);
            const dimensions = { width: bitmap.width, height: bitmap.height };
            if (typeof bitmap.close === 'function') {
                bitmap.close();
            }
            return dimensions;
        } catch (error) {
            if (import.meta.env.DEV) {
                console.warn('createImageBitmap failed, falling back to Image()', error);
            }
        }
    }

    // Fallback: Image()
    return new Promise((resolve, reject) => {
        const image = new Image();
        const objectUrl = URL.createObjectURL(file);

        const timeoutId = setTimeout(() => {
            URL.revokeObjectURL(objectUrl);
            reject(new Error('Image decode timeout exceeded.'));
        }, IMAGE_READ_TIMEOUT_MS);

        const cleanup = () => {
            clearTimeout(timeoutId);
            URL.revokeObjectURL(objectUrl);
        };

        image.decoding = 'async';

        image.onload = () => {
            cleanup();
            const width = image.naturalWidth || image.width;
            const height = image.naturalHeight || image.height;

            if (!width || !height) {
                reject(new Error('Invalid image dimensions.'));
                return;
            }

            resolve({ width, height });
        };

        image.onerror = () => {
            cleanup();
            reject(new Error('Unable to read image dimensions.'));
        };

        image.src = objectUrl;
    });
}

function resolveCurrentOrigin(locationCandidate: string | null | undefined): string | null {
    const candidates = [locationCandidate, typeof window !== 'undefined' ? window.location?.origin : null].filter(Boolean);

    for (const candidate of candidates) {
        try {
            return new URL(candidate as string).origin;
        } catch {
            continue;
        }
    }

    return null;
}

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

async function detectMimeType(file: File): Promise<string | null> {
    try {
        const buffer = await file.slice(0, 12).arrayBuffer();
        const bytes = new Uint8Array(buffer);

        // JPEG
        if (matchesMagicNumber(bytes, MAGIC_NUMBERS.JPEG)) {
            return 'image/jpeg';
        }

        // PNG
        if (matchesMagicNumber(bytes, MAGIC_NUMBERS.PNG)) {
            return 'image/png';
        }

        // GIF
        if (matchesMagicNumber(bytes, MAGIC_NUMBERS.GIF)) {
            return 'image/gif';
        }

        // WebP
        if (matchesMagicNumberWithOffset(bytes, MAGIC_NUMBERS.WEBP)) {
            return 'image/webp';
        }

        // AVIF
        if (matchesMagicNumberWithOffset(bytes, MAGIC_NUMBERS.AVIF)) {
            return 'image/avif';
        }
    } catch (error) {
        if (import.meta.env.DEV) {
            console.warn('Unable to inspect file header for MIME detection.', error);
        }
    }

    return null;
}

function matchesMagicNumber(bytes: Uint8Array, signature: readonly number[]): boolean {
    if (bytes.length < signature.length) return false;
    return signature.every((byte, index) => bytes[index] === byte);
}

function matchesMagicNumberWithOffset(bytes: Uint8Array, config: { offset: number; bytes: readonly number[] }): boolean {
    if (bytes.length < config.offset + config.bytes.length) return false;
    return config.bytes.every((byte, index) => bytes[config.offset + index] === byte);
}

function buildAllowedSummary(mimeTypes: readonly string[]): string {
    return mimeTypes.map((mime) => MIME_EXTENSION_LABELS[mime] ?? mime).join(', ');
}

function getExtensionFromName(name: string | undefined): string | null {
    if (!name) return null;
    const match = name.split('.').pop();
    return match ? match.trim().toLowerCase() : null;
}

function matchesAllowedMime(file: File, allowed: Set<string>, fallbackExtensions: readonly string[]): boolean {
    const mime = file.type?.toLowerCase() ?? '';

    if (mime && allowed.has(mime)) {
        return true;
    }

    const extension = getExtensionFromName(file.name);
    return extension ? fallbackExtensions.includes(extension) : false;
}

// ============================================================================
// ERROR HANDLING UTILITIES
// ============================================================================

function coerceMessage(value: unknown): string | undefined {
    if (typeof value === 'string' && value.trim() !== '') {
        return value.trim();
    }
    return undefined;
}

function pickAnyErrorMessage(bag: Record<string, unknown> | undefined): string | undefined {
    if (!bag || typeof bag !== 'object' || Array.isArray(bag)) {
        return undefined;
    }

    const firstKey = Object.keys(bag)[0];
    if (!firstKey) return undefined;

    const value = bag[firstKey];

    if (Array.isArray(value)) {
        return value.map(coerceMessage).find(Boolean);
    }

    return coerceMessage(value);
}

function extractPayloadMessage(payload: unknown): string | undefined {
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
            // No es JSON válido
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
}

function pickServerMessage(err: NormalizedError): string | undefined {
    if (err && typeof err === 'object') {
        const direct = coerceMessage(err.message) ?? extractPayloadMessage(err.data);
        if (direct) return direct;

        const statusMessage = coerceMessage(err.statusText);
        if (statusMessage) return statusMessage;
    }

    return undefined;
}

function pickErrorFromBag(bag: ErrorBag, field = 'avatar'): string | undefined {
    const value = bag[field];
    if (!Array.isArray(value)) return undefined;
    return value.map(coerceMessage).find(Boolean);
}

function sanitizeMessage(message: string | undefined, fallback: string): string {
    if (!message) return fallback;

    const trimmed = message.trim();

    // Filtro de mensajes genéricos inútiles
    if (/excepci[oó]n no controlada/i.test(trimmed) || /^uncontrolled exception/i.test(trimmed)) {
        return fallback;
    }

    return trimmed;
}

function resolveAvatarErrorMessage(err: NormalizedError, normalized: ErrorBag, fallback: string): string {
    const message =
        pickServerMessage(err) ??
        pickErrorFromBag(normalized) ??
        pickAnyErrorMessage(err.errors as Record<string, unknown> | undefined) ??
        coerceMessage(err.message) ??
        coerceMessage(err.statusText) ??
        fallback;

    return sanitizeMessage(message, fallback);
}

function normalizeErrors(errorBag: Record<string, string | string[]>): ErrorBag {
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
}

function createRejection(message: string, meta: RejectionMeta = {}): Promise<never> {
    const { status = 0, aborted = false, name = 'HttpError', toastShown = false } = meta;

    const error: any = Object.assign(new Error(message), {
        status,
        aborted,
        name,
        toastShown,
    });

    return Promise.reject(error);
}

function emitAvatarErrorToast(message: string): boolean {
    if (typeof message !== 'string' || message.trim() === '') {
        return false;
    }

    notify.error(message);
    return true;
}

function rejectWithAvatarError(message: string, meta: RejectionMeta = {}): Promise<never> {
    const toastShown = emitAvatarErrorToast(message);

    return createRejection(message, {
        ...meta,
        toastShown: meta.toastShown ?? toastShown,
    });
}

// ============================================================================
// MAIN COMPOSABLE
// ============================================================================

export function useAvatarUpload(options: UseAvatarUploadOptions = {}) {
    const { t, locale } = useI18n();

    // ========================================================================
    // CONFIGURATION
    // ========================================================================

    const config = {
        uploadRoute: options.uploadRoute ?? DEFAULT_OPTIONS.uploadRoute,
        deleteRoute: options.deleteRoute ?? DEFAULT_OPTIONS.deleteRoute,
        refreshAuthOnSuccess: options.refreshAuthOnSuccess ?? DEFAULT_OPTIONS.refreshAuthOnSuccess,
    };

    const constraints: AvatarValidationConstraints = {
        ...DEFAULT_CONSTRAINTS,
        ...options.constraints,
    };

    const allowedMimeSet = new Set(constraints.allowedMimeTypes.map((mime) => mime.toLowerCase()));
    const allowedExtensions = constraints.allowedMimeTypes.map((mime) => mime.split('/').pop() ?? '').filter(Boolean);
    const allowedSummary = buildAllowedSummary(constraints.allowedMimeTypes);
    const acceptAttribute = constraints.allowedMimeTypes.join(',');

    // ========================================================================
    // PAGE PROPS & ZIGGY
    // ========================================================================

    const page = usePage<AppPageProps>();

    const ziggyConfig = computed<Config | null>(() => {
        const config = page.props.ziggy as Config | undefined;
        return config ?? null;
    });

    const currentOrigin = computed<string | null>(() => {
        const ziggyLocation = page.props.ziggy?.location ?? null;
        return resolveCurrentOrigin(ziggyLocation);
    });

    const authUser = computed<User | null>(() => (page.props.auth?.user as User | undefined) ?? null);

    // ========================================================================
    // STATE MANAGEMENT
    // ========================================================================

    const avatarCacheBuster = ref<number>(0);
    const avatarOverride = ref<AvatarOverride | null>(null);
    const currentUpload = ref<OngoingUpload | null>(null);

    // UI State
    const isUploading = ref(false);
    const isDeleting = ref(false);
    const uploadProgress = ref<number | null>(null);
    const errors = ref<ErrorBag>({});
    const generalError = ref<string | null>(null);
    const recentlySuccessful = ref<boolean>(false);
    const successMessage = ref<string | null>(null);

    // ========================================================================
    // COMPUTED PROPERTIES
    // ========================================================================

    const hasAvatar = computed<boolean>(() => getRawAvatarValue(authUser.value) !== null);

    // ========================================================================
    // AVATAR URL RESOLUTION
    // ========================================================================

    const getRawAvatarValue = (user: User | null | undefined): string | null => {
        if (!user) return null;

        const candidates = [
            avatarOverride.value?.avatar_thumb_url,
            avatarOverride.value?.avatar_url,
            user.avatar_thumb_url,
            user.avatar_url,
            user.avatar,
        ];

        for (const candidate of candidates) {
            if (typeof candidate === 'string') {
                const trimmed = candidate.trim();
                if (trimmed !== '') return trimmed;
            }
        }

        return null;
    };

    const resolveAvatarUrl = (user: User | null | undefined): string | null => {
        const base = getRawAvatarValue(user);
        if (!base) return null;

        const normalizedBase = normalizeAssetHost(base, currentOrigin.value);

        const versionCandidate = avatarOverride.value?.avatar_version ?? user?.avatar_version;
        const cacheToken = versionCandidate != null ? String(versionCandidate) : avatarCacheBuster.value;

        if (!cacheToken || (typeof cacheToken === 'number' && cacheToken <= 0)) {
            return normalizedBase;
        }

        const separator = normalizedBase.includes('?') ? '&' : '?';
        return `${normalizedBase}${separator}${CACHE_BUSTER_PARAM}=${encodeURIComponent(cacheToken)}`;
    };

    // ========================================================================
    // STATE MANAGEMENT HELPERS
    // ========================================================================

    const setLocalAvatarOverride = (override?: AvatarOverride | null) => {
        if (!override) {
            avatarOverride.value = null;
            return;
        }

        avatarOverride.value = {
            avatar_url: typeof override.avatar_url === 'string' ? override.avatar_url.trim() : (override.avatar_url ?? null),
            avatar_thumb_url: typeof override.avatar_thumb_url === 'string' ? override.avatar_thumb_url.trim() : (override.avatar_thumb_url ?? null),
            avatar_version: override.avatar_version ?? null,
        };

        avatarCacheBuster.value = Date.now();
    };

    const resetErrors = () => {
        errors.value = {};
        generalError.value = null;
    };

    const setAvatarError = (message: string) => {
        errors.value = { ...errors.value, avatar: [message] };
        generalError.value = message;
    };

    const refreshAuth = () => {
        if (!config.refreshAuthOnSuccess) return;

        try {
            router.reload({ only: ['auth', 'flash'] });
        } catch (error) {
            if (import.meta.env.DEV) {
                console.warn('Failed to reload auth data after avatar operation.', error);
            }
        }
    };

    const showSuccessMessage = (message: string) => {
        notify.event(message);
        successMessage.value = message;
        recentlySuccessful.value = true;
        setTimeout(() => (recentlySuccessful.value = false), SUCCESS_MESSAGE_DURATION);
    };

    // ========================================================================
    // UPLOAD CONTROL
    // ========================================================================

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

    // ========================================================================
    // VALIDATION
    // ========================================================================

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

        if (file.size > constraints.maxBytes) {
            const maxFormatted = formatBytes(constraints.maxBytes, locale.value);
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

        if (metadata.width < constraints.minDimension || metadata.height < constraints.minDimension) {
            const message = t('profile.avatar_error_dimensions', { min: constraints.minDimension });
            setAvatarError(message);
            throw new Error(message);
        }

        const megapixels = (metadata.width * metadata.height) / 1_000_000;

        if (megapixels > constraints.maxMegapixels + 0.001) {
            const message = t('profile.avatar_error_megapixels', { max: constraints.maxMegapixels });
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

    // ========================================================================
    // ERROR HANDLERS
    // ========================================================================

    const handleUploadError = (error: unknown, normalized: ErrorBag, fallback: string): Promise<never> => {
        const err = error as NormalizedError;
        const status = err.status || 0;

        // Detecta abort/cancel
        const isAborted =
            currentUpload.value?.controller?.signal?.aborted === true ||
            (err as { aborted?: boolean })?.aborted === true ||
            (err as { name?: string })?.name === 'AbortError';

        if (isAborted) {
            const message = t('profile.avatar_error_cancelled_upload');
            setAvatarError(message);
            return createRejection(message, { status: 0, aborted: true, name: 'AbortError' });
        }

        const message = resolveAvatarErrorMessage(err, normalized, fallback);

        // 422: Validation error
        if (status === 422) {
            errors.value = normalized;
            setAvatarError(message);
            return rejectWithAvatarError(message, { status: 422, name: 'HttpError' });
        }

        // 403: Forbidden
        if (status === 403) {
            setAvatarError(message);
            return rejectWithAvatarError(message, { status: 403, name: 'HttpError' });
        }

        // 429: Rate limit
        if (status === 429) {
            const retry = err.retryAfter ?? 0;
            const fullMessage = retry > 0 ? `${message} (retry in ${retry}s)` : message;
            setAvatarError(fullMessage);
            return rejectWithAvatarError(fullMessage, { status: 429, name: 'HttpError' });
        }

        // Default: Generic error
        setAvatarError(message);
        const errorName = status === 0 ? 'NetworkError' : 'HttpError';
        return rejectWithAvatarError(message, { status, name: errorName });
    };

    // ========================================================================
    // UPLOAD AVATAR
    // ========================================================================

    const uploadAvatar = async (file: File | null | undefined): Promise<AvatarUploadSuccessPayload> => {
        resetErrors();

        if (!(file instanceof File)) {
            const message = t('profile.avatar_error_required');
            setAvatarError(message);
            return rejectWithAvatarError(message, { status: 0, name: 'ClientValidationError' });
        }

        // Client-side validation
        let validation: UploadValidationResult;
        try {
            validation = await validateAvatarFile(file);
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            return rejectWithAvatarError(message, { status: 0, name: 'ClientValidationError' });
        }

        // Resolve upload URL
        let uploadUrl: string;
        try {
            uploadUrl = resolveRoute(config.uploadRoute, ziggyConfig.value);
        } catch {
            const message = t('profile.avatar_error_route_missing');
            setAvatarError(message);
            return rejectWithAvatarError(message, { status: 0, name: 'ConfigError' });
        }

        // Cancel any previous upload
        abortCurrentUpload({ silent: true });

        // Prepare upload
        const controller = SUPPORTS_ABORT ? new AbortController() : null;
        currentUpload.value = { controller };

        const formData = new FormData();
        formData.append('avatar', file);

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

            const payload = (response?.data ?? {}) as Record<string, unknown>;
            const override: AvatarOverride = {
                avatar_url: typeof payload.avatar_url === 'string' ? payload.avatar_url : null,
                avatar_thumb_url: typeof payload.avatar_thumb_url === 'string' ? payload.avatar_thumb_url : null,
                avatar_version:
                    typeof payload.avatar_version === 'number' || typeof payload.avatar_version === 'string' ? payload.avatar_version : null,
            };

            setLocalAvatarOverride(override);
            refreshAuth();

            // Update cache buster
            avatarCacheBuster.value = Date.now() + Math.floor(Math.random() * 1000);

            const message = typeof payload.message === 'string' ? payload.message : 'Avatar actualizado correctamente.';
            showSuccessMessage(message);

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

            return handleUploadError(error, normalized, fallback);
        } finally {
            currentUpload.value = null;
            isUploading.value = false;
            uploadProgress.value = null;
        }
    };

    // ========================================================================
    // REMOVE AVATAR
    // ========================================================================

    const removeAvatar = async (): Promise<void> => {
        resetErrors();
        abortCurrentUpload({ silent: true });

        // Resolve delete URL
        let deleteUrl: string;
        try {
            deleteUrl = resolveRoute(config.deleteRoute, ziggyConfig.value);
        } catch {
            const message = t('profile.avatar_error_route_missing');
            setAvatarError(message);
            return rejectWithAvatarError(message, { status: 0, name: 'ConfigError' });
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

            const payload = (response?.data ?? {}) as Record<string, unknown>;
            const override: AvatarOverride = {
                avatar_url: typeof payload.avatar_url === 'string' ? payload.avatar_url : null,
                avatar_thumb_url: typeof payload.avatar_thumb_url === 'string' ? payload.avatar_thumb_url : null,
                avatar_version:
                    typeof payload.avatar_version === 'number' || typeof payload.avatar_version === 'string' ? payload.avatar_version : null,
            };

            setLocalAvatarOverride(override.avatar_url || override.avatar_thumb_url ? override : null);
            refreshAuth();

            avatarCacheBuster.value = Date.now() + Math.floor(Math.random() * 1000);

            const message = typeof payload.message === 'string' ? payload.message : 'Avatar eliminado correctamente.';
            showSuccessMessage(message);

            return;
        } catch (error) {
            const err = error as NormalizedError;
            const status = err.status || 0;
            const normalized = normalizeErrors((err.errors as Record<string, string | string[]> | undefined) ?? {});
            const fallback = status === 0 ? t('profile.avatar_error_network') : t('profile.avatar_error_generic');

            return handleUploadError(error, normalized, fallback);
        } finally {
            isDeleting.value = false;
        }
    };

    // ========================================================================
    // LIFECYCLE
    // ========================================================================

    onBeforeUnmount(() => {
        abortCurrentUpload({ silent: true });
    });

    // ========================================================================
    // PUBLIC API
    // ========================================================================

    return {
        // User data
        authUser,
        hasAvatar,

        // Upload state
        isUploading,
        isDeleting,
        uploadProgress,

        // Errors
        errors,
        generalError,

        // Success
        recentlySuccessful,
        successMessage,

        // Actions
        uploadAvatar,
        removeAvatar,
        resetErrors,
        cancelUpload: abortCurrentUpload,

        // Utilities
        resolveAvatarUrl,
        avatarCacheBuster,
        formatBytesLabel: (bytes: number) => formatBytes(bytes, locale.value),

        // Configuration
        constraints,
        allowedMimeSummary: allowedSummary,
        acceptMimeTypes: acceptAttribute,
    };
}
