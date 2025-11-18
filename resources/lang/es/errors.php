<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Líneas de Idioma para Errores
    |--------------------------------------------------------------------------
    | Mensajes claros y útiles, sin tecnicismos innecesarios.
    */

    // Códigos de Error HTTP
    '400' => [
        'title' => 'Solicitud incorrecta',
        'message' => 'No entendemos lo que nos pides.',
        'description' => 'Revisa la dirección o el formulario y vuelve a intentarlo.',
    ],
    '401' => [
        'title' => 'Inicia sesión',
        'message' => 'Necesitas tener una cuenta para ver esto.',
        'description' => 'Entra con tu usuario y contraseña. Si no tienes cuenta, créala primero.',
    ],
    '403' => [
        'title' => 'No puedes entrar aquí',
        'message' => 'No tienes permiso para acceder.',
        'description' => 'Si crees que deberías poder entrar, contacta con el administrador.',
    ],
    '404' => [
        'title' => 'No encontrado',
        'message' => 'Esta página no existe o se ha movido.',
        'description' => 'Comprueba la dirección o vuelve al inicio.',
    ],
    '405' => [
        'title' => 'Acción no permitida',
        'message' => 'No puedes hacer eso aquí.',
        'description' => 'Usa los botones y enlaces de la página para navegar.',
    ],
    '408' => [
        'title' => 'Se acabó el tiempo',
        'message' => 'La petición tardó demasiado.',
        'description' => 'Comprueba tu conexión y prueba otra vez.',
    ],
    '422' => [
        'title' => 'Datos incorrectos',
        'message' => 'Hay errores en el formulario.',
        'description' => 'Revisa los campos marcados y corrígelos.',
    ],
    '429' => [
        'title' => 'Demasiadas peticiones',
        'message' => 'Has hecho muchas peticiones seguidas.',
        'description' => 'Espera un momento y vuelve a intentarlo.',
    ],
    '500' => [
        'title' => 'Error del servidor',
        'message' => 'Algo ha fallado en nuestro sistema.',
        'description' => 'Estamos trabajando en solucionarlo. Vuelve en unos minutos.',
    ],
    '502' => [
        'title' => 'Error de conexión',
        'message' => 'Un servicio externo no responde correctamente.',
        'description' => 'Suele ser temporal. Inténtalo más tarde.',
    ],
    '503' => [
        'title' => 'En mantenimiento',
        'message' => 'Estamos haciendo mejoras.',
        'description' => 'Volveremos enseguida. Gracias por esperar.',
    ],
    '504' => [
        'title' => 'Tiempo agotado',
        'message' => 'Un servicio tardó demasiado en responder.',
        'description' => 'Vuelve a intentarlo en un rato.',
    ],

    // Errores de Aplicación
    'general_error' => 'Algo ha fallado. Prueba otra vez.',
    'unexpected_error' => 'Error inesperado. Lo estamos revisando.',
    'system_error' => 'Error del sistema. Vuelve a intentarlo.',
    'database_error' => 'Error con la base de datos.',
    'connection_error' => 'No se pudo conectar.',
    'authentication_error' => 'No hemos podido verificarte. Vuelve a entrar.',
    'authorization_error' => 'No tienes permiso para hacer esto.',
    'validation_error' => 'Hay errores en los datos.',
    'file_error' => 'Problema con el archivo.',
    'upload_error' => 'No se pudo subir el archivo.',
    'download_error' => 'No se pudo descargar el archivo.',
    'email_error' => 'No se pudo enviar el correo.',
    'notification_error' => 'No se pudo enviar la notificación.',

    // Mensajes de Error Amigables para el Usuario
    'something_went_wrong' => 'Algo ha salido mal.',
    'try_again_later' => 'Vuelve a intentarlo en unos minutos.',
    'contact_support' => 'Si sigue pasando, contacta con soporte.',
    'check_your_input' => 'Revisa los datos del formulario.',
    'refresh_page' => 'Actualiza la página y prueba otra vez.',
    'clear_browser_cache' => 'Limpia la caché del navegador.',
    'check_internet_connection' => 'Comprueba tu conexión a internet.',
    'try_different_browser' => 'Prueba con otro navegador.',
    'restart_application' => 'Cierra y vuelve a abrir la aplicación.',

    // Mensajes de Error Específicos
    'invalid_credentials' => 'Usuario o contraseña incorrectos.',
    'account_locked' => 'Tu cuenta está bloqueada temporalmente.',
    'account_disabled' => 'Tu cuenta está desactivada.',
    'session_expired' => 'Tu sesión ha caducado. Vuelve a entrar.',
    'permission_denied' => 'No tienes permiso.',
    'resource_not_found' => 'No encontramos lo que buscas.',
    'resource_already_exists' => 'Esto ya existe.',
    'resource_in_use' => 'Está siendo usado ahora mismo.',
    'invalid_file_format' => 'Formato de archivo no compatible.',
    'file_too_large' => 'El archivo es demasiado grande.',
    'invalid_email_format' => 'El email no es válido.',
    'password_too_weak' => 'La contraseña es muy débil. Usa más caracteres.',
    'username_taken' => 'Ese usuario ya existe.',
    'email_already_registered' => 'Ese email ya está registrado.',
    'invalid_token' => 'El enlace no es válido o ha caducado.',
    'rate_limit_exceeded' => 'Demasiados intentos. Espera un momento.',
    'rate_limit_wait' => 'Has hecho demasiadas peticiones. Espera :seconds segundos antes de intentarlo de nuevo.',

    // Errores de Base de Datos
    'database_connection_failed' => 'No podemos conectar con la base de datos.',
    'database_query_failed' => 'Error en la base de datos.',
    'database_transaction_failed' => 'No se pudieron guardar los cambios.',
    'database_constraint_violation' => 'Conflicto con datos existentes.',
    'database_deadlock' => 'Bloqueo de base de datos. Intenta otra vez.',
    'database_timeout' => 'La base de datos tardó demasiado.',

    // Errores de Red
    'network_unreachable' => 'No hay conexión de red.',
    'connection_refused' => 'La conexión fue rechazada.',
    'connection_reset' => 'La conexión se cortó.',
    'connection_aborted' => 'La conexión se canceló.',
    'host_unreachable' => 'No se puede acceder al servidor.',
    'dns_lookup_failed' => 'No encontramos el servidor.',

    // Errores de Seguridad
    'access_denied' => 'Acceso denegado.',
    'forbidden_action' => 'Esta acción no está permitida.',
    'insufficient_permissions' => 'Permisos insuficientes.',
    'security_violation' => 'Acción no permitida.',
    'suspicious_activity' => 'Actividad sospechosa detectada.',
    'account_compromised' => 'Actividad extraña en tu cuenta. Cambia la contraseña.',

    // Errores de Formularios
    'form_validation_failed' => 'Revisa los errores del formulario.',
    'required_field_missing' => 'Este campo es obligatorio.',
    'invalid_field_value' => 'El valor no es válido.',
    'field_too_short' => 'Demasiado corto.',
    'field_too_long' => 'Demasiado largo.',
    'field_format_invalid' => 'Formato incorrecto.',
    'field_already_exists' => 'Este valor ya existe.',
    'field_confirmation_mismatch' => 'La confirmación no coincide.',

    // Operaciones de Archivos
    'file_not_found' => 'Archivo no encontrado.',
    'file_cannot_be_read' => 'No se puede leer el archivo.',
    'file_cannot_be_written' => 'No se puede guardar el archivo.',
    'file_cannot_be_deleted' => 'No se pudo eliminar el archivo.',
    'file_cannot_be_moved' => 'No se pudo mover el archivo.',
    'file_cannot_be_copied' => 'No se pudo copiar el archivo.',
    'file_permission_denied' => 'Sin permisos para este archivo.',
    'file_is_corrupted' => 'El archivo está dañado.',
    'file_is_empty' => 'El archivo está vacío.',
    'file_type_not_supported' => 'Tipo de archivo no compatible.',

    // Errores de Email
    'email_send_failed' => 'No se pudo enviar el correo.',
    'email_invalid_recipient' => 'El destinatario no es válido.',
    'email_invalid_sender' => 'El remitente no es válido.',
    'email_template_not_found' => 'Plantilla de correo no encontrada.',
    'email_attachment_failed' => 'No se pudo adjuntar el archivo.',
    'email_queue_failed' => 'No se pudo encolar el correo.',

    // Errores de Notificaciones
    'notification_send_failed' => 'No se pudo enviar la notificación.',
    'notification_template_not_found' => 'Plantilla de notificación no encontrada.',
    'notification_channel_unavailable' => 'Canal de notificación no disponible.',
    'notification_recipient_invalid' => 'Destinatario no válido.',

    // Errores de API
    'api_endpoint_not_found' => 'Endpoint no encontrado.',
    'api_method_not_allowed' => 'Método no permitido.',
    'api_authentication_failed' => 'Error de autenticación.',
    'api_authorization_failed' => 'Sin permisos para esta petición.',
    'api_rate_limit_exceeded' => 'Límite de peticiones superado.',
    'api_invalid_request' => 'Petición no válida.',
    'api_server_error' => 'Error en la API.',

    // Mantenimiento
    'maintenance_mode_active' => 'En mantenimiento.',
    'maintenance_scheduled' => 'Mantenimiento programado.',
    'maintenance_estimated_duration' => 'Tiempo estimado de mantenimiento.',
    'maintenance_reason' => 'Motivo del mantenimiento.',

    // Mensajes Genéricos (HTTP varios)
    'unknown_error' => 'Error desconocido.',
    'unhandled_exception' => 'Excepción no controlada.',
    'internal_server_error' => 'Error interno.',
    'service_unavailable' => 'Servicio no disponible.',
    'bad_request' => 'Petición incorrecta.',
    'not_found' => 'No encontrado.',
    'method_not_allowed' => 'Método no permitido.',
    'request_timeout' => 'Tiempo agotado.',
    'conflict' => 'Conflicto.',
    'gone' => 'Ya no disponible.',
    'length_required' => 'Falta longitud.',
    'precondition_failed' => 'Precondición fallida.',
    'payload_too_large' => 'Contenido demasiado grande.',
    'uri_too_long' => 'URL demasiado larga.',
    'unsupported_media_type' => 'Tipo de contenido no compatible.',
    'range_not_satisfiable' => 'Rango no válido.',
    'expectation_failed' => 'Expectativa no cumplida.',
    'im_a_teapot' => 'Somos una tetera (418). ☕️',
    'misdirected_request' => 'Petición mal dirigida.',
    'unprocessable_entity' => 'No se puede procesar.',
    'locked' => 'Bloqueado.',
    'failed_dependency' => 'Dependencia fallida.',
    'too_early' => 'Demasiado pronto.',
    'upgrade_required' => 'Necesitas actualizar.',
    'precondition_required' => 'Falta precondición.',
    'too_many_requests' => 'Demasiadas peticiones.',
    'request_header_fields_too_large' => 'Encabezados demasiado grandes.',
    'unavailable_for_legal_reasons' => 'No disponible por razones legales.',
    'not_implemented' => 'No implementado.',
    'bad_gateway' => 'Puerta de enlace incorrecta.',
    'gateway_timeout' => 'Tiempo agotado en puerta de enlace.',
    'http_version_not_supported' => 'Versión HTTP no soportada.',
    'variant_also_negotiates' => 'Conflicto de contenido.',
    'insufficient_storage' => 'Sin espacio suficiente.',
    'loop_detected' => 'Bucle detectado.',
    'not_extended' => 'Extensiones no soportadas.',
    'network_authentication_required' => 'Requiere autenticación de red.',
    'page_expired' => 'Página caducada. Actualiza y prueba otra vez.',
];
