import type { PageProps } from '@inertiajs/core';
import { usePage } from '@inertiajs/vue3';
import { ref, type Ref } from 'vue';
import { notify } from '@/plugins/toaster-plugin';
import { route } from 'ziggy-js';
import { sendFormData, type NormalizedError } from '@/lib/http/xhrFormData';

/**
 * Tipo para representar los errores de campo de formulario
 *
 * @example { file: ["El archivo es demasiado grande."] }
 */
type FieldErrors = Record<string, string[]>; // Ej.: { file: ["..."] }

/**
 * Tipo para representar la respuesta exitosa de una subida de archivo
 *
 * @interface UploadSuccess
 * @property {string|number} id - ID único del upload (UUID o número)
 * @property {string|number} profile_id - Identificador del perfil de upload
 * @property {string} status - Estado actual del upload (por ejemplo: "queued", "processing")
 * @property {string} [correlation_id] - ID de correlación opcional para seguimiento
 */
export type UploadSuccess = {
    id: string | number; // Ej.: "uuid"
    profile_id: string | number; // Ej.: "document_pdf"
    status: string; // Ej.: "queued"
    correlation_id?: string | null; // Ej.: "req_abc-123"
};

/**
 * Tipo para representar la solicitud de subida de archivo
 *
 * @interface UploadRequest
 * @property {File} file - Archivo a subir
 * @property {string} profileId - Identificador del perfil de upload
 * @property {string} [correlationId] - ID de correlación opcional
 * @property {Record<string, unknown>} [metadata] - Metadatos adicionales para el upload
 */
type UploadRequest = {
    file: File; // Ej.: input.files[0]
    profileId: string; // Ej.: "document_pdf"
    correlationId?: string; // Ej.: "req_abc-123"
    metadata?: Record<string, unknown>; // Ej.: { note: "Factura enero" }
};

/**
 * Opciones para configurar la subida de archivos
 *
 * @interface UploadOptions
 * @property {(percent: number) => void} [onProgress] - Callback para recibir el progreso de subida
 * @property {AbortSignal} [signal] - Señal para cancelar la subida
 * @property {string} [urlOverride] - URL personalizada para la subida (reemplaza la predeterminada)
 */
type UploadOptions = {
    onProgress?: (percent: number) => void; // Ej.: percent => progress.value=percent
    signal?: AbortSignal; // Ej.: controller.signal
    urlOverride?: string; // Ej.: "/uploads"
};

// Mensaje genérico de error para casos no manejados específicamente
const GENERIC_ERROR = 'Ha ocurrido un error inesperado. Intenta de nuevo.'; // Ej.: fallback

/**
 * Composable para manejar subidas de archivos
 *
 * Proporciona un conjunto de utilidades para subir archivos con estado,
 * manejo de errores, progreso y cancelación.
 *
 * @param {string} [storeRouteName='uploads.store'] - Nombre de la ruta para subidas (usada con Ziggy)
 * @returns {Object} Objeto con propiedades reactivas y métodos para controlar la subida
 */
export function useUploads(storeRouteName = 'uploads.store') {
    const page = usePage<PageProps>(); // Ej.: page.props.ziggy
    const ziggyConfig = page.props.ziggy ?? null;

    // Estados reactivos para controlar el proceso de subida
    const isUploading = ref(false); // Ej.: true mientras sube
    const uploadProgress = ref<number | null>(null); // Ej.: 0..100
    const errors: Ref<FieldErrors> = ref({}); // Ej.: { file: ["..."] }
    const generalError = ref<string | null>(null); // Ej.: "No autorizado..."
    const lastResult = ref<UploadSuccess | null>(null); // Ej.: resultado 201
    let activeController: AbortController | null = null; // Ej.: para cancelar

    /**
     * Resuelve la URL para la operación de subida
     *
     * @param {string} [override] - URL personalizada para usar en lugar de la predeterminada
     * @returns {string} URL para la operación de subida
     */
    const resolveStoreUrl = (override?: string): string => {
        if (override) return override; // Ej.: "/uploads"
        if (ziggyConfig) return route(storeRouteName, undefined, false, ziggyConfig); // Ej.: ruta Ziggy
        return '/uploads'; // Ej.: fallback
    };

    /**
     * Restablece todos los estados relacionados con la subida
     */
    const resetState = () => {
        errors.value = {}; // Ej.: limpia errores
        generalError.value = null; // Ej.: limpia mensaje general
        lastResult.value = null; // Ej.: limpia success
    };

    /**
     * Cancela la subida de archivo activa
     */
    const cancelUpload = () => {
        if (activeController) {
            activeController.abort(); // Ej.: cancela XHR
            activeController = null; // Ej.: libera
        }
    };

    /**
     * Realiza la subida de archivo
     *
     * @param {UploadRequest} payload - Datos para la subida
     * @param {UploadOptions} [options={}] - Opciones adicionales para la subida
     * @returns {Promise<UploadSuccess>} Promesa que resuelve con la respuesta del servidor
     */
    const upload = async (payload: UploadRequest, options: UploadOptions = {}) => {
        resetState(); // Ej.: arranca limpio
        const controller = new AbortController(); // Ej.: controller abort
        activeController = controller; // Ej.: guarda activo

        // Prepara los datos del formulario
        const formData = new FormData(); // Ej.: multipart
        formData.append('file', payload.file); // Ej.: file
        formData.append('profile_id', payload.profileId); // Ej.: "document_pdf"

        if (payload.correlationId) formData.append('correlation_id', payload.correlationId); // Ej.: "req_abc-123"

        if (payload.metadata) {
            Object.entries(payload.metadata).forEach(([key, value]) => {
                if (value === undefined || value === null) return; // Ej.: skip null
                formData.append(`meta[${key}]`, String(value)); // Ej.: meta[note]="Factura"
            });
        }

        isUploading.value = true; // Ej.: UI disable
        uploadProgress.value = 0; // Ej.: barra

        try {
            const response = await sendFormData({
                url: resolveStoreUrl(options.urlOverride),
                method: 'POST',
                formData,
                onProgress: options.onProgress ?? ((percent) => (uploadProgress.value = percent)),
                signal: options.signal ?? controller.signal,
            });

            const result = response.data as UploadSuccess;
            lastResult.value = result;
            uploadProgress.value = 100;
            return result;
        } catch (error) {
            const err = error as NormalizedError;
            const status = err.status || 0;
            const fieldErrors = (err.errors as FieldErrors | undefined) ?? {};

            // Manejo específico de diferentes códigos de error
            if (status === 422) {
                errors.value = fieldErrors; // Ej.: { file: ["..."] }
                generalError.value = err.message ?? GENERIC_ERROR; // Ej.: message
            } else if (status === 403) {
                const message = err.message ?? 'No autorizado para subir archivos.'; // Ej.: 403
                notify.error(message); // Ej.: toast
                generalError.value = message; // Ej.: UI
            } else if (status === 429) {
                const seconds = err.retryAfter ?? 0; // Ej.: 10
                const message = seconds > 0 ? `Demasiadas solicitudes. Intenta en ${seconds}s.` : 'Demasiadas solicitudes.'; // Ej.: "Intenta en 10s"
                notify.warning(message); // Ej.: toast
                generalError.value = message; // Ej.: UI
            } else if (status >= 500 || status === 0) {
                console.error('Upload failed', err); // Ej.: debug
                const message = err.message ?? GENERIC_ERROR; // Ej.: fallback
                notify.error(message); // Ej.: toast
                generalError.value = message; // Ej.: UI
            }

            throw err; // Ej.: permite handling externo si quieres
        } finally {
            isUploading.value = false; // Ej.: habilita UI
            activeController = null; // Ej.: limpia
        }
    };

    // Retorna el API público del composable
    return { isUploading, uploadProgress, errors, generalError, lastResult, upload, cancelUpload }; // Ej.: API composable
}
