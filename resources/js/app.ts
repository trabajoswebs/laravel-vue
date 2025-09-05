import '../css/app.css';
import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from 'ziggy-js';
import { initializeTheme } from './composables/useAppearance';
import { i18n } from './i18n';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { ToasterPlugin } from './plugins/toaster-plugin';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

// Función para sanitizar mensajes HTML
function sanitizeMessage(message: unknown): string {
    if (!message) return '';
    
    const str = String(message);
    
    // Sanitización básica contra XSS
    return str
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;')
        .replace(/&/g, '&amp;')
        .substring(0, 500); // Limitar longitud
}

// Validar estructura de flash messages
function isValidFlashMessage(flash: unknown): flash is Record<string, unknown> {
    return flash !== null && 
           typeof flash === 'object' && 
           !Array.isArray(flash);
}

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .use(i18n)
            .use(ToasterPlugin)
            .mount(el);
    },
    progress: {
        color: '#4B5563',
    },
});

// Inicializar tema
initializeTheme();

// Listener global mejorado y seguro
router.on('success', (event) => {
    try {
        // Validación estricta de la estructura del evento
        const page = event?.detail?.page;
        if (!page || typeof page !== 'object') return;

        const flash = page.props?.flash;
        if (!isValidFlashMessage(flash) || Object.keys(flash).length === 0) return;

        // Priorizar errores primero (incluyendo success === false)
        if (flash.error || flash.success === false) {
            const errorMsg = flash.error || 'Operation failed';
            const sanitized = sanitizeMessage(errorMsg);
            if (sanitized) {
                toast.error(sanitized);
            }
            return;
        }

        // Manejar éxitos
        if (flash.success === true || flash.success) {
            const successMsg = flash.message || 'Operación realizada correctamente';
            const sanitized = sanitizeMessage(successMsg);
            if (sanitized) {
                toast.success(sanitized);
            }
            return;
        }

        // Mensajes informativos (solo si no hay error ni éxito)
        if (flash.message && !flash.error && flash.success !== false) {
            const infoMsg = sanitizeMessage(flash.message);
            if (infoMsg) {
                toast.message(infoMsg);
            }
        }

    } catch (error) {
        console.warn('Error processing flash message:', error);
        // No mostrar toast de error para evitar bucles
    }
});