import { App, createApp, h } from 'vue';
import { Toaster as VueSonner } from 'vue-sonner';

export interface ToasterConfig {
    position?: 'top-left' | 'top-center' | 'top-right' | 'bottom-left' | 'bottom-center' | 'bottom-right';
    duration?: number;
    closeButton?: boolean;
    theme?: 'light' | 'dark' | 'system';
    expand?: boolean;
    visibleToasts?: number;
    gap?: number;
}

// Singleton para prevenir múltiples instancias
class ToasterManager {
    private static instance: ToasterManager | null = null;
    private toasterApp: App | null = null;
    private container: HTMLElement | null = null;
    private isInitialized = false;

    private constructor() {}

    static getInstance(): ToasterManager {
        if (!ToasterManager.instance) {
            ToasterManager.instance = new ToasterManager();
        }
        return ToasterManager.instance;
    }

    initialize(config: ToasterConfig = {}) {
        // Prevenir múltiples inicializaciones
        if (this.isInitialized) {
            console.warn('[ToasterPlugin] Ya está inicializado. Ignorando reinicialización.');
            return;
        }

        try {
            // Verificar entorno del navegador
            if (typeof document === 'undefined') {
                console.warn('[ToasterPlugin] No disponible en entorno SSR');
                return;
            }

            // Configuración por defecto empresarial
            const defaultConfig: Required<ToasterConfig> = {
                position: 'bottom-right',
                duration: 4000,
                closeButton: true,
                theme: 'system',
                expand: false,
                visibleToasts: 5,
                gap: 8,
                ...config,
            };

            // Validar configuración
            this.validateConfig(defaultConfig);

            // Crear contenedor de forma segura
            this.createContainer();

            // Crear aplicación Vue para el Toaster
            this.toasterApp = createApp({
                name: 'GlobalToaster',
                render() {
                    return h(VueSonner, {
                        ...defaultConfig,
                        class: 'toaster group',
                    });
                },
            });

            // Configurar manejo de errores
            this.toasterApp.config.errorHandler = (err) => {
                console.error('[ToasterPlugin] Error en Toaster:', err);
            };

            // Montar el Toaster
            if (this.container) {
                this.toasterApp.mount(this.container);
                this.isInitialized = true;
            }
        } catch (error) {
            console.error('[ToasterPlugin] Error durante inicialización:', error);
            this.cleanup();
        }
    }

    private createContainer() {
        // Verificar si ya existe un contenedor
        const existingContainer = document.getElementById('global-toaster');
        if (existingContainer) {
            console.warn('[ToasterPlugin] Contenedor ya existe. Reutilizando.');
            this.container = existingContainer;
            return;
        }

        // Crear nuevo contenedor
        this.container = document.createElement('div');
        this.container.id = 'global-toaster';
        this.container.setAttribute('data-sonner-toaster', '');

        // Agregar al DOM de forma segura
        if (document.body) {
            document.body.appendChild(this.container);
        } else {
            throw new Error('document.body no está disponible');
        }
    }

    private validateConfig(config: Required<ToasterConfig>) {
        const validPositions = ['top-left', 'top-center', 'top-right', 'bottom-left', 'bottom-center', 'bottom-right'];

        if (!validPositions.includes(config.position)) {
            throw new Error(`Posición inválida: ${config.position}`);
        }

        if (config.duration < 1000 || config.duration > 30000) {
            throw new Error('Duración debe estar entre 1000ms y 30000ms');
        }

        const validThemes = ['light', 'dark', 'system'];
        if (!validThemes.includes(config.theme)) {
            throw new Error(`Tema inválido: ${config.theme}`);
        }
    }

    cleanup() {
        try {
            if (this.toasterApp) {
                this.toasterApp.unmount();
                this.toasterApp = null;
            }

            if (this.container && this.container.parentNode) {
                this.container.parentNode.removeChild(this.container);
                this.container = null;
            }

            this.isInitialized = false;
        } catch (error) {
            console.error('[ToasterPlugin] Error durante cleanup:', error);
        }
    }

    isReady(): boolean {
        return this.isInitialized;
    }
}

export const ToasterPlugin = {
    install(app: App, config: ToasterConfig = {}) {
        const manager = ToasterManager.getInstance();

        // Inicializar el toaster
        manager.initialize(config);

        // Proporcionar método de cleanup a la app principal
        app.config.globalProperties.$cleanupToaster = () => {
            manager.cleanup();
        };

        // Cleanup automático cuando la app se desmonta
        const originalUnmount = app.unmount;
        app.unmount = function () {
            manager.cleanup();
            return originalUnmount.call(this);
        };

        // Método para verificar si está listo
        app.config.globalProperties.$isToasterReady = () => {
            return manager.isReady();
        };
    },
};

// Función de utilidad para cleanup manual si es necesario
export const cleanupToaster = () => {
    ToasterManager.getInstance().cleanup();
};
