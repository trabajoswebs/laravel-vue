import { getCsrfToken } from './csrf';

type HttpMethod = 'POST' | 'PATCH' | 'DELETE';

type SendFormDataOptions = {
    url: string;
    method?: HttpMethod;
    formData?: FormData;
    signal?: AbortSignal;
    onProgress?: (percent: number) => void;
};

export type NormalizedError = {
    status: number;
    message?: string;
    errors?: Record<string, unknown>;
    retryAfter?: number;
    statusText?: string;
    data?: unknown;
};

export type SendFormDataResult = {
    status: number;
    data: unknown;
    headers: Record<string, string>;
};

const defaultHeaders = () => {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };

    // Usamos X-XSRF-TOKEN (estándar Laravel/Axios); Laravel lo desencripta.
    const csrf = getCsrfToken();
    if (csrf) {
        headers['X-XSRF-TOKEN'] = csrf;
    }

    return headers;
};

const normalizeHeaders = (raw: string | null): Record<string, string> => {
    if (!raw) return {};

    return raw
        .trim()
        .split(/[\r\n]+/)
        .reduce((acc, line) => {
            const parts = line.split(': ');
            const key = parts.shift();
            const value = parts.join(': ');
            if (key) {
                acc[key.trim().toLowerCase()] = value.trim();
            }
            return acc;
        }, {} as Record<string, string>);
};

export function normalizeError(status: number, payload: unknown, retryAfterHeader?: string | null, statusText?: string): NormalizedError {
    const retryAfter = retryAfterHeader ? Number.parseInt(retryAfterHeader, 10) : undefined;

    if (typeof payload === 'string') {
        const trimmed = payload.trim();
        // Intenta parsear JSON para extraer message/errors cuando el responseType falla
        if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
            try {
                const parsed = JSON.parse(trimmed);
                if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
                    const data = parsed as Record<string, unknown>;
                    const errors = (data.errors as Record<string, unknown> | undefined) ?? undefined;
                    const message = typeof data.message === 'string' ? data.message : undefined;
                    const errorText = typeof data.error === 'string' ? data.error : undefined;
                    return {
                        status,
                        message: message ?? errorText ?? trimmed,
                        errors,
                        retryAfter,
                        statusText,
                        data,
                    };
                }
            } catch (error) {
                // Si no es JSON válido, seguimos con el string plano
            }
        }

        return { status, message: trimmed !== '' ? trimmed : undefined, retryAfter, statusText, data: payload };
    }

    if (payload && typeof payload === 'object' && !Array.isArray(payload)) {
        const data = payload as Record<string, unknown>;
        const errors = (data.errors as Record<string, unknown> | undefined) ?? undefined;
        const message = typeof data.message === 'string' ? data.message : undefined;
        return { status, message, errors, retryAfter, statusText, data };
    }

    return { status, retryAfter, statusText, data: payload };
}

export async function sendFormData(options: SendFormDataOptions): Promise<SendFormDataResult> {
    const { url, method = 'POST', formData = new FormData(), signal, onProgress } = options;

    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(method, url);
        xhr.responseType = 'json';

        const headers = defaultHeaders();
        Object.entries(headers).forEach(([key, value]) => xhr.setRequestHeader(key, value));

        if (onProgress) {
            xhr.upload.onprogress = (event) => {
                if (!event.lengthComputable) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                onProgress(percent);
            };
        }

        const abortHandler = () => xhr.abort();
        if (signal) {
            signal.addEventListener('abort', abortHandler, { once: true });
        }

        xhr.onerror = () => {
            const payload = xhr.response ?? xhr.responseText ?? null;
            reject(normalizeError(xhr.status || 0, payload, xhr.getResponseHeader('Retry-After'), xhr.statusText));
        };
        xhr.onabort = () => {
            const payload = xhr.response ?? xhr.responseText ?? null;
            reject(normalizeError(0, payload, xhr.getResponseHeader('Retry-After'), xhr.statusText));
        };

        xhr.onload = () => {
            const status = xhr.status;
            const payload = xhr.response ?? xhr.responseText ?? null;
            const retryAfter = xhr.getResponseHeader('Retry-After');
            const headersMap = normalizeHeaders(xhr.getAllResponseHeaders());

            if (status >= 200 && status < 300) {
                resolve({
                    status,
                    data: payload,
                    headers: headersMap,
                });
                return;
            }

            reject(normalizeError(status, payload, retryAfter, xhr.statusText));
        };

        xhr.send(formData);
    });
}
