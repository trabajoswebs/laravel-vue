import '../css/app.css';
import type { Errors, Page } from '@inertiajs/core';
import { createInertiaApp, router } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h } from 'vue';
import type { DefineComponent } from 'vue';
import type { Config } from 'ziggy-js';
import { ZiggyVue } from 'ziggy-js';

import { initializeTheme } from './composables/useAppearance';
import { i18n, safeT } from './i18n';
import { notify, toastVisuals, ToasterPlugin, type ToastVariant } from './plugins/toaster-plugin';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const MAX_MESSAGE_LENGTH = 500;

type FlashPayload = {
    success?: unknown;
    message?: unknown;
    warning?: unknown;
    error?: unknown;
    event?: unknown;
    description?: unknown;
    details?: unknown;
};

type EventFlash = {
    title: string;
    description?: string;
    variant?: ToastVariant;
};

type InertiaSuccessEvent = CustomEvent<{ page: Page }>;

type HttpLikeError = {
    message?: unknown;
    response?: { status?: number };
    status?: number;
};

type InertiaErrorEvent = CustomEvent<{
    error?: HttpLikeError;
    errors?: Errors;
}>;

type InertiaToastListeners = {
    successOff?: () => void;
    errorOff?: () => void;
};

declare global {
    interface Window {
        __INERTIA_TOAST_LISTENERS__?: InertiaToastListeners;
    }
}

const sanitizeMessage = (message: unknown): string => {
    if (message === null || message === undefined) {
        return '';
    }

    return String(message)
        .slice(0, MAX_MESSAGE_LENGTH)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
};

const toText = (value: unknown): string | undefined => {
    if (value === null || value === undefined) {
        return undefined;
    }

    const plain = typeof value === 'string' ? value : String(value);
    if (plain.trim() === '') {
        return undefined;
    }

    const sanitized = sanitizeMessage(plain.trim());
    return sanitized || undefined;
};

const isFlashPayload = (value: unknown): value is FlashPayload => {
    return value !== null && typeof value === 'object' && !Array.isArray(value);
};

const normalizeToastVariant = (variant: unknown): ToastVariant | undefined => {
    if (typeof variant !== 'string') {
        return undefined;
    }

    const normalized = variant.trim().toLowerCase();
    return Object.prototype.hasOwnProperty.call(toastVisuals, normalized) ? (normalized as ToastVariant) : undefined;
};

const translate = (key: string): string => safeT(key);

const extractDescription = (flash: FlashPayload): string | undefined => {
    return toText(flash.description ?? flash.details);
};

const extractEventFlash = (raw: unknown): EventFlash | null => {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
        return null;
    }

    const source = raw as Record<string, unknown>;
    const title = toText(source.title);
    if (!title) {
        return null;
    }

    return {
        title,
        description: toText(source.description),
        variant: normalizeToastVariant(source.variant),
    };
};

const showToast = (variant: ToastVariant, title: string, description?: string | null): void => {
    notify.show(variant, title, description ?? undefined);
};

const handleFlash = (rawFlash: unknown): boolean => {
    if (!isFlashPayload(rawFlash)) {
        return false;
    }

    const flash = rawFlash;
    if (Object.keys(flash).length === 0) {
        return false;
    }

    const eventFlash = extractEventFlash(flash.event);
    if (eventFlash) {
        showToast(eventFlash.variant ?? 'event', eventFlash.title, eventFlash.description);
        return true;
    }

    if (flash.error || flash.success === false) {
        const message = toText(flash.error) ?? sanitizeMessage(translate('flash.default_error'));
        showToast('error', message, extractDescription(flash));
        return true;
    }

    const hasSuccess = flash.success === true || typeof flash.success === 'string';
    if (hasSuccess) {
        const messageSource = flash.message ?? flash.success ?? translate('flash.default_success');
        const message = toText(messageSource) ?? sanitizeMessage(translate('flash.default_success'));
        showToast('success', message, extractDescription(flash));
        return true;
    }

    const warning = toText(flash.warning);
    if (warning) {
        showToast('warning', warning, extractDescription(flash));
        return true;
    }

    if (!flash.error && flash.success !== false) {
        const info = toText(flash.message);
        if (info) {
            showToast('info', info, extractDescription(flash));
            return true;
        }
    }

    return false;
};

const extractFlashFromPage = (page: unknown): unknown => {
    if (!page || typeof page !== 'object' || Array.isArray(page)) {
        return undefined;
    }

    const props = (page as { props?: unknown }).props;
    if (!props || typeof props !== 'object' || Array.isArray(props)) {
        return undefined;
    }

    return (props as { flash?: unknown }).flash;
};

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        const ziggyConfig = (props.initialPage.props as Record<string, unknown> | undefined)?.ziggy;
        if (ziggyConfig && typeof ziggyConfig === 'object') {
            const ziggy = ziggyConfig as Record<string, unknown>;
            const normalizedZiggy = {
                ...ziggy,
                location: typeof ziggy.location === 'string' ? new URL(ziggy.location) : ziggy.location,
            } as Config;

            if (typeof window !== 'undefined') {
                (window as typeof window & { Ziggy?: Config }).Ziggy = normalizedZiggy;
            }

            app.use(ZiggyVue, normalizedZiggy);
        } else {
            app.use(ZiggyVue);
        }

        app.use(plugin).use(i18n).use(ToasterPlugin).mount(el);

        const initialFlash = extractFlashFromPage(props.initialPage);
        handleFlash(initialFlash);
    },
    progress: {
        color: '#4B5563',
    },
});

initializeTheme();

const registerInertiaToastListeners = (): void => {
    const existing = typeof window !== 'undefined' ? window.__INERTIA_TOAST_LISTENERS__ : undefined;
    existing?.successOff?.();
    existing?.errorOff?.();

    const successOff = router.on('success', (event: InertiaSuccessEvent) => {
        const flash = extractFlashFromPage(event?.detail?.page);
        handleFlash(flash);
    });

    const errorOff = router.on('error', (event: InertiaErrorEvent) => {
        const detail = event?.detail;
        const err = detail?.error;
        const status = err?.response?.status ?? err?.status;

        if (typeof status !== 'number' || status < 500) {
            return;
        }

        let rawMessage: unknown = err?.message;
        if (!rawMessage && detail?.errors) {
            const firstEntry = Object.values(detail.errors)[0];
            rawMessage = Array.isArray(firstEntry) ? firstEntry[0] : firstEntry;
        }

        const baseMessage = toText(rawMessage) ?? sanitizeMessage(translate('flash.default_error'));
        showToast('error', `${baseMessage} (HTTP ${status})`);
    });

    if (typeof window !== 'undefined') {
        window.__INERTIA_TOAST_LISTENERS__ = { successOff, errorOff };
    }
};

registerInertiaToastListeners();

if (import.meta.hot) {
    import.meta.hot.dispose(() => {
        if (typeof window !== 'undefined') {
            window.__INERTIA_TOAST_LISTENERS__?.successOff?.();
            window.__INERTIA_TOAST_LISTENERS__?.errorOff?.();
            window.__INERTIA_TOAST_LISTENERS__ = undefined;
        }
    });
}
