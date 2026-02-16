import { App, createApp, h } from 'vue';
import { Toaster as VueSonner, toast } from 'vue-sonner';

export type ToastVariant = 'success' | 'warning' | 'error' | 'info' | 'event';

export const toastVisuals: Record<
    ToastVariant,
    {
        className: string;
        icon: string;
    }
> = {
    success: {
        className: 'toast-success',
        icon: '/icons/toast-success.svg',
    },
    warning: {
        className: 'toast-warning',
        icon: '/icons/toast-warning.svg',
    },
    error: {
        className: 'toast-error',
        icon: '/icons/toast-error.svg',
    },
    info: {
        className: 'toast-info',
        icon: '/icons/toast-info.svg',
    },
    event: {
        className: 'toast-success toast-event',
        icon: '/icons/toast-success.svg',
    },
} as const;

const TOAST_DEDUP_WINDOW_MS = 1200;
const GENERIC_FALLBACK_SUPPRESS_WINDOW_MS = 2200;
const GENERIC_AVATAR_ERROR_TITLES = new Set([
    'no pudimos actualizar tu avatar. inténtalo de nuevo.',
    'we could not update your avatar. please try again.',
]);

type ToastFingerprint = {
    key: string;
    at: number;
    variant: ToastVariant;
    title: string;
};

let lastToastFingerprint: ToastFingerprint | null = null;

function normalizeToastText(value: string | null | undefined): string {
    return (value ?? '').trim().toLowerCase();
}

function isGenericAvatarFallback(variant: ToastVariant, title: string): boolean {
    if (variant !== 'error') {
        return false;
    }

    return GENERIC_AVATAR_ERROR_TITLES.has(normalizeToastText(title));
}

function shouldSkipDuplicatedToast(variant: ToastVariant, title: string, description?: string | null): boolean {
    const normalizedTitle = title.trim();
    const normalizedDescription = (description ?? '').trim();
    const key = `${variant}::${normalizedTitle}::${normalizedDescription}`;
    const now = Date.now();

    if (lastToastFingerprint && lastToastFingerprint.key === key && now - lastToastFingerprint.at <= TOAST_DEDUP_WINDOW_MS) {
        return true;
    }

    // Cuando ya existe un error detallado reciente, ignora el fallback genérico del uploader.
    if (
        lastToastFingerprint &&
        isGenericAvatarFallback(variant, normalizedTitle) &&
        lastToastFingerprint.variant === 'error' &&
        normalizeToastText(lastToastFingerprint.title) !== normalizeToastText(normalizedTitle) &&
        now - lastToastFingerprint.at <= GENERIC_FALLBACK_SUPPRESS_WINDOW_MS
    ) {
        return true;
    }

    lastToastFingerprint = {
        key,
        at: now,
        variant,
        title: normalizedTitle,
    };

    return false;
}

const buildToastOptions = (variant: ToastVariant, description?: string | null) => {
    const visuals = toastVisuals[variant];
    const iconSizeClass = variant === 'event' ? 'h-4 w-4' : 'h-5 w-5';

    return {
        class: visuals.className,
        richColors: true,
        description: description ?? undefined,
        icon: h('img', {
            src: visuals.icon,
            alt: '',
            class: iconSizeClass,
            'aria-hidden': 'true',
        }),
    } as const;
};

const showToast = (variant: ToastVariant, title: string, description?: string | null) => {
    if (shouldSkipDuplicatedToast(variant, title, description)) {
        return;
    }

    const options = buildToastOptions(variant, description);

    if (variant === 'event') {
        toast(title, options);
        return;
    }

    const toastMethod =
        variant === 'info'
            ? 'info'
            : variant === 'warning'
              ? 'warning'
              : variant === 'error'
                ? 'error'
                : 'success';

    toast[toastMethod](title, options);
};

export const notify = {
    show: showToast,
    success: (title: string, description?: string | null) => showToast('success', title, description),
    warning: (title: string, description?: string | null) => showToast('warning', title, description),
    error: (title: string, description?: string | null) => showToast('error', title, description),
    info: (title: string, description?: string | null) => showToast('info', title, description),
    event: (title: string, description?: string | null) => showToast('event', title, description),
} as const;

export interface ToasterConfig {
    position?: 'top-left' | 'top-center' | 'top-right' | 'bottom-left' | 'bottom-center' | 'bottom-right';
    duration?: number;
    closeButton?: boolean;
    theme?: 'light' | 'dark' | 'system';
    expand?: boolean;
    visibleToasts?: number;
    gap?: number;
}

declare global {
    interface Window {
        __GLOBAL_TOASTER_INITIALIZED__?: boolean;
    }
}

class ToasterManager {
    private static instance: ToasterManager | null = null;
    private toasterApp: App | null = null;
    private container: HTMLElement | null = null;

    private constructor() {}

    static getInstance(): ToasterManager {
        if (!ToasterManager.instance) {
            ToasterManager.instance = new ToasterManager();
        }
        return ToasterManager.instance;
    }

    initialize(config: ToasterConfig = {}): void {
        if (typeof document === 'undefined') {
            return;
        }

        if (this.toasterApp || window.__GLOBAL_TOASTER_INITIALIZED__ === true) {
            return;
        }

        const mergedConfig: Required<ToasterConfig> = {
            position: 'bottom-right',
            duration: 4000,
            closeButton: true,
            theme: 'system',
            expand: false,
            visibleToasts: 5,
            gap: 8,
            ...config,
        };

        this.container = document.getElementById('global-toaster');
        if (!this.container) {
            this.container = document.createElement('div');
            this.container.id = 'global-toaster';
            this.container.setAttribute('data-sonner-toaster', '');
            document.body.appendChild(this.container);
        }

        this.toasterApp = createApp({
            name: 'GlobalToaster',
            render() {
                return h(VueSonner, {
                    ...mergedConfig,
                    class: 'toaster group',
                });
            },
        });

        this.toasterApp.mount(this.container);
        window.__GLOBAL_TOASTER_INITIALIZED__ = true;
    }

    cleanup(): void {
        if (this.toasterApp) {
            this.toasterApp.unmount();
            this.toasterApp = null;
        }

        if (this.container?.parentNode) {
            this.container.parentNode.removeChild(this.container);
        }

        this.container = null;
        if (typeof window !== 'undefined') {
            window.__GLOBAL_TOASTER_INITIALIZED__ = false;
        }
    }

    isReady(): boolean {
        return this.toasterApp !== null;
    }
}

export const ToasterPlugin = {
    install(app: App, config: ToasterConfig = {}) {
        const manager = ToasterManager.getInstance();
        manager.initialize(config);

        app.config.globalProperties.$cleanupToaster = () => {
            manager.cleanup();
        };

        const originalUnmount = app.unmount;
        app.unmount = function () {
            manager.cleanup();
            return originalUnmount.call(this);
        };

        app.config.globalProperties.$isToasterReady = () => manager.isReady();
    },
};

export const cleanupToaster = () => {
    ToasterManager.getInstance().cleanup();
};
