/**
 * Dependencias principales del frontend.
 * Se importan estilos, utilidades de Inertia/Vue y el plugin de toasts.
 */
import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { Errors, Page } from '@inertiajs/core';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import type { Config } from 'ziggy-js';
import { initializeTheme } from './composables/useAppearance';
import { i18n } from './i18n';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ToasterPlugin } from './plugins/toaster-plugin';

/**
 * Nombre por defecto de la app y límite de caracteres permitidos en los mensajes.
 * - Ejemplo: si VITE_APP_NAME no existe, `appName` será "Laravel".
 */
const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const MAX_MESSAGE_LENGTH = 500;

/**
 * Estructura genérica del payload "flash" que llega desde el backend.
 * Permite valores dinámicos sin perder tipado básico.
 */
type FlashPayload = {
    success?: unknown;
    message?: unknown;
    warning?: unknown;
    error?: unknown;
    event?: unknown;
    description?: unknown;
    details?: unknown;
    [key: string]: unknown;
};

type EventFlash = {
    title: string;
    description?: string;
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

/**
 * Sanitiza cualquier mensaje recibido para evitar XSS.
 * @param message Valor desconocido que podría provenir de sesión.
 * @returns Cadena segura sin etiquetas peligrosas.
 * @example sanitizeMessage('<b>Hola</b>') => '&lt;b&gt;Hola&lt;/b&gt;'
 */
function sanitizeMessage(message: unknown): string {
    if (message === null || message === undefined) {
        return '';
    }

    const str = String(message).slice(0, MAX_MESSAGE_LENGTH);

    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Convierte un valor cualquiera en texto seguro y no vacío.
 * @param value Texto libre o cualquier tipo.
 * @returns Cadena sanitizada o undefined si el texto queda vacío.
 * @example toSanitizedNonEmptyString('  Hola  ') => 'Hola'
 */
function toSanitizedNonEmptyString(value: unknown): string | undefined {
    if (value === null || value === undefined) {
        return undefined;
    }

    const plain = typeof value === 'string' ? value : String(value);
    if (plain.trim() === '') {
        return undefined;
    }

    const sanitized = sanitizeMessage(plain.trim());
    return sanitized === '' ? undefined : sanitized;
}

/**
 * Comprueba que el payload flash tenga estructura de objeto simple.
 * @param flash Payload desconocido proveniente de Inertia.
 * @returns Verdadero si se puede tratar como FlashPayload.
 * @example isValidFlashMessage({ message: 'Hola' }) => true
 */
function isValidFlashMessage(flash: unknown): flash is FlashPayload {
    return flash !== null && typeof flash === 'object' && !Array.isArray(flash);
}

/**
 * Configuración visual de cada tipo de toast.
 * Incluye un tema especial `event` con fondo blanco y tipografía oscura.
 */
const toastVisuals = {
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
        className: 'toast-event',
        icon: '/icons/toast-event.svg',
    },
} as const;

type ToastVariant = keyof typeof toastVisuals;

/**
 * Función de ayuda para traducir claves almacenadas en i18n.
 * @example translate('flash.default_success') => 'Operación exitosa'
 */
const translate = (key: string): string => String(i18n.global.t(key));

/**
 * Extrae una descripción adicional del flash (`description` o `details`).
 * @param flash Payload ya validado.
 * @returns Descripción segura o undefined si no existe.
 * @example extractDescription({ description: 'Más info' }) => 'Más info'
 */
const extractDescription = (flash: FlashPayload): string | undefined => {
    return toSanitizedNonEmptyString(flash.description ?? flash.details);
};

/**
 * Convierte un payload arbitrario en un EventFlash válido.
 * @param raw Puede ser cualquier valor almacenado en flash.event.
 * @returns Objeto con `title` obligatorio o null si falta información.
 * @example extractEventFlash({ title: 'Pago confirmado' }) => { title: 'Pago confirmado' }
 */
const extractEventFlash = (raw: FlashPayload['event']): EventFlash | null => {
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) {
        return null;
    }

    const source = raw as Record<string, unknown>;
    const title = toSanitizedNonEmptyString(source.title);
    if (!title) {
        return null;
    }

    const description = toSanitizedNonEmptyString(source.description);

    return {
        title,
        description,
    };
};

/**
 * Manejador específico para eventos con estilo dedicado.
 * Prioridad: muestra toasts con fondo blanco, texto negro e icono oscuro.
 * @returns true si se mostró un toast, false en caso contrario.
 */
const handleEventFlash = (flash: FlashPayload): boolean => {
    const eventFlash = extractEventFlash(flash.event);
    if (!eventFlash) {
        return false;
    }

    showToast('event', eventFlash.title, eventFlash.description);
    return true;
};

/**
 * Manejador de errores: considera `flash.error` o `flash.success === false`.
 * @returns true si se mostró un toast de error.
 * @example Con flash.error = 'Falló la operación' se muestra un toast rojo.
 */
const handleErrorFlash = (flash: FlashPayload): boolean => {
    if (!flash.error && flash.success !== false) {
        return false;
    }

    const message = toSanitizedNonEmptyString(flash.error) ?? sanitizeMessage(translate('flash.default_error'));
    showToast('error', message, extractDescription(flash));
    return true;
};

/**
 * Manejador de éxitos: acepta `flash.success === true` o un texto en success.
 * @returns true si se mostró un toast verde de éxito.
 * @example flash.success = true y flash.message = 'Guardado' => toast success.
 */
const handleSuccessFlash = (flash: FlashPayload): boolean => {
    const hasSuccessFlag = flash.success === true || typeof flash.success === 'string';
    if (!hasSuccessFlag) {
        return false;
    }

    const messageSource = flash.message ?? flash.success ?? translate('flash.default_success');
    const message = toSanitizedNonEmptyString(messageSource) ?? sanitizeMessage(translate('flash.default_success'));
    showToast('success', message, extractDescription(flash));
    return true;
};

/**
 * Manejador de advertencias: usa `flash.warning` como mensaje principal.
 * @returns true si se mostró un toast amarillo de advertencia.
 */
const handleWarningFlash = (flash: FlashPayload): boolean => {
    if (!flash.warning) {
        return false;
    }

    const message = toSanitizedNonEmptyString(flash.warning);
    if (!message) {
        return false;
    }

    showToast('warning', message, extractDescription(flash));
    return true;
};

/**
 * Manejador informativo por defecto cuando no hay error/éxito.
 * @returns true si se mostró un toast azul con `flash.message`.
 */
const handleInfoFlash = (flash: FlashPayload): boolean => {
    if (!flash.message || flash.error || flash.success === false) {
        return false;
    }

    const message = toSanitizedNonEmptyString(flash.message);
    if (!message) {
        return false;
    }

    showToast('info', message, extractDescription(flash));
    return true;
};

/**
 * Lista ordenada de manejadores. El primero que procese el flash detiene el resto.
 * Orden de prioridad: event > error > success > warning > info.
 */
const flashHandlers: Array<(flash: FlashPayload) => boolean> = [
    handleEventFlash,
    handleErrorFlash,
    handleSuccessFlash,
    handleWarningFlash,
    handleInfoFlash,
];

/**
 * Orquestador que valida y recorre los handlers.
 * @param rawFlash Payload recibido desde Inertia (puede ser cualquier cosa).
 * @returns true si algún handler mostró un toast.
 * @example handleFlash({ success: true, message: 'Listo' }) => true
 */
const handleFlash = (rawFlash: unknown): boolean => {
    if (!isValidFlashMessage(rawFlash)) {
        return false;
    }

    const flash = rawFlash as FlashPayload;
    if (Object.keys(flash).length === 0) {
        return false;
    }

    return flashHandlers.some((handler) => handler(flash));
};

/**
 * Wrapper central para mostrar un toast con estilos consistentes.
 * @param variant Tipo de toast (event, info, warning, error o success).
 * @param title Título principal del mensaje.
 * @param description Texto opcional que aparece debajo del título.
 * @example showToast('event', 'Recordatorio', 'Mañana 9:00 AM') => toast blanco con icono oscuro.
 */
const showToast = (variant: ToastVariant, title: string, description?: string | null) => {
    const visuals = toastVisuals[variant];
    const iconSizeClass = variant === 'event' ? 'h-4 w-4' : 'h-5 w-5';

    const sharedOptions = {
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

    if (variant === 'event') {
        toast(title, sharedOptions);
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

    toast[toastMethod](title, sharedOptions);
};

/**
 * Extrae la propiedad flash de cualquier respuesta de Inertia reduciendo riesgos.
 * @param page Objeto `page` que Inertia entrega en las navegaciones.
 * @returns El payload flash o undefined si no existe.
 * @example extractFlashFromPage({ props: { flash: {...} } }) => {...}
 */
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

/**
 * Punto de entrada de Inertia. Monta la app y procesa el flash inicial.
 */
createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) });

        const ziggyConfig = (props.initialPage.props as Record<string, unknown> | undefined)?.ziggy;
        if (ziggyConfig && typeof ziggyConfig === 'object') {
            const normalizedZiggy = {
                ...ziggyConfig,
                location:
                    typeof ziggyConfig.location === 'string'
                        ? new URL(ziggyConfig.location)
                        : ziggyConfig.location,
            } as Config;

            // Mantener Ziggy disponible de forma global para utilidades que lo lean desde window.
            if (typeof window !== 'undefined') {
                (window as typeof window & { Ziggy?: Config }).Ziggy = normalizedZiggy;
            }

            app.use(ZiggyVue, normalizedZiggy);
        } else {
            app.use(ZiggyVue);
        }

        app
            .use(plugin)
            .use(i18n)
            .use(ToasterPlugin)
            .mount(el);

        const initialFlash = extractFlashFromPage(props.initialPage);
        handleFlash(initialFlash);
    },
    progress: {
        color: '#4B5563',
    },
});

// Inicializar tema
initializeTheme();

/**
 * Listener que reacciona a cada navegación exitosa de Inertia.
 * Obtiene el nuevo flash y trata de mostrar un toast según corresponda.
 */
router.on('success', (event: InertiaSuccessEvent) => {
    try {
        // Validación estricta de la estructura del evento
        const page = event?.detail?.page;
        if (!page || typeof page !== 'object') return;

        const flash = extractFlashFromPage(page);
        handleFlash(flash);

    } catch (error) {
        console.warn('Error processing flash message:', error);
        // No mostrar toast de error para evitar bucles
    }
});

/**
 * Listener de errores globales. Muestra un toast rojo con detalles HTTP.
 * - Ejemplo: error 403 => "Error genérico (HTTP 403)".
 */
router.on('error', (event: InertiaErrorEvent) => {
    const detail = event?.detail;
    const err = detail?.error;
    const status = err?.response?.status ?? err?.status;

    let rawMessage: unknown = err?.message;

    if (!rawMessage && detail?.errors) {
        const firstEntry = Object.values(detail.errors)[0];
        rawMessage = Array.isArray(firstEntry) ? firstEntry[0] : firstEntry;
    }

    const baseMessage = typeof rawMessage === 'string' && rawMessage.trim() !== '' ? rawMessage : translate('flash.default_error');
    const combinedMessage = status ? `${baseMessage} (HTTP ${status})` : baseMessage;

    showToast('error', sanitizeMessage(combinedMessage));

    if (err) {
        console.error('Inertia error event:', err);
    }
});
