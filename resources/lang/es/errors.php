<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Líneas de Idioma para Errores
    |--------------------------------------------------------------------------
    |
    | Las siguientes líneas de idioma se utilizan para mensajes de error
    | que aparecen en toda la interfaz de la aplicación.
    |
    */

    // Códigos de Error HTTP
    '400' => [
        'title' => 'Solicitud Incorrecta',
        'message' => 'El servidor no pudo entender la solicitud.',
        'description' => 'El servidor no puede procesar tu solicitud debido a sintaxis inválida.',
    ],
    '401' => [
        'title' => 'No Autorizado',
        'message' => 'No estás autorizado para acceder a este recurso.',
        'description' => 'Por favor, inicia sesión con credenciales válidas para continuar.',
    ],
    '403' => [
        'title' => 'Prohibido',
        'message' => 'El acceso a este recurso está prohibido.',
        'description' => 'No tienes permiso para acceder a esta página o recurso.',
    ],
    '404' => [
        'title' => 'Página No Encontrada',
        'message' => 'La página que buscas no se pudo encontrar.',
        'description' => 'La página puede haber sido movida, eliminada, o has introducido una URL incorrecta.',
    ],
    '405' => [
        'title' => 'Método No Permitido',
        'message' => 'El método HTTP utilizado no está permitido para este recurso.',
        'description' => 'Por favor, utiliza un método HTTP diferente para acceder a este recurso.',
    ],
    '408' => [
        'title' => 'Tiempo de Espera Agotado',
        'message' => 'La solicitud agotó el tiempo de espera mientras esperaba una respuesta.',
        'description' => 'El servidor tardó demasiado en responder. Por favor, inténtalo de nuevo.',
    ],
    '422' => [
        'title' => 'Entidad No Procesable',
        'message' => 'La solicitud estaba bien formada pero contiene datos inválidos.',
        'description' => 'Por favor, revisa tu entrada e inténtalo de nuevo.',
    ],
    '429' => [
        'title' => 'Demasiadas Solicitudes',
        'message' => 'Has realizado demasiadas solicitudes en poco tiempo.',
        'description' => 'Por favor, espera un momento antes de intentarlo de nuevo.',
    ],
    '500' => [
        'title' => 'Error Interno del Servidor',
        'message' => 'Algo salió mal en nuestro servidor.',
        'description' => 'Estamos experimentando dificultades técnicas. Por favor, inténtalo de nuevo más tarde.',
    ],
    '502' => [
        'title' => 'Puerta de Enlace Incorrecta',
        'message' => 'El servidor recibió una respuesta inválida de un servidor aguas arriba.',
        'description' => 'Estamos experimentando problemas de conectividad. Por favor, inténtalo de nuevo más tarde.',
    ],
    '503' => [
        'title' => 'Servicio No Disponible',
        'message' => 'El servicio no está disponible temporalmente.',
        'description' => 'Estamos realizando mantenimiento. Por favor, vuelve más tarde.',
    ],
    '504' => [
        'title' => 'Tiempo de Espera de Puerta de Enlace',
        'message' => 'El servidor no recibió una respuesta oportuna.',
        'description' => 'La solicitud tardó demasiado en procesarse. Por favor, inténtalo de nuevo.',
    ],

    // Errores de Aplicación
    'general_error' => 'Ha ocurrido un error',
    'unexpected_error' => 'Ha ocurrido un error inesperado',
    'system_error' => 'Ha ocurrido un error del sistema',
    'database_error' => 'Ha ocurrido un error de base de datos',
    'connection_error' => 'Ha ocurrido un error de conexión',
    'authentication_error' => 'Ha ocurrido un error de autenticación',
    'authorization_error' => 'Ha ocurrido un error de autorización',
    'validation_error' => 'Ha ocurrido un error de validación',
    'file_error' => 'Ha ocurrido un error de archivo',
    'upload_error' => 'Ha ocurrido un error de subida',
    'download_error' => 'Ha ocurrido un error de descarga',
    'email_error' => 'Ha ocurrido un error de email',
    'notification_error' => 'Ha ocurrido un error de notificación',

    // Mensajes de Error Amigables para el Usuario
    'something_went_wrong' => 'Algo salió mal',
    'try_again_later' => 'Por favor, inténtalo de nuevo más tarde',
    'contact_support' => 'Si el problema persiste, por favor contacta con soporte',
    'check_your_input' => 'Por favor, revisa tu entrada e inténtalo de nuevo',
    'refresh_page' => 'Por favor, actualiza la página e inténtalo de nuevo',
    'clear_browser_cache' => 'Por favor, limpia la caché de tu navegador e inténtalo de nuevo',
    'check_internet_connection' => 'Por favor, revisa tu conexión a internet e inténtalo de nuevo',
    'try_different_browser' => 'Por favor, prueba usando un navegador diferente',
    'restart_application' => 'Por favor, reinicia la aplicación e inténtalo de nuevo',

    // Mensajes de Error Específicos
    'invalid_credentials' => 'Credenciales inválidas proporcionadas',
    'account_locked' => 'Tu cuenta ha sido bloqueada',
    'account_disabled' => 'Tu cuenta ha sido deshabilitada',
    'session_expired' => 'Tu sesión ha expirado',
    'permission_denied' => 'Permiso denegado para esta acción',
    'resource_not_found' => 'El recurso solicitado no fue encontrado',
    'resource_already_exists' => 'El recurso ya existe',
    'resource_in_use' => 'El recurso está actualmente en uso',
    'invalid_file_format' => 'Formato de archivo inválido',
    'file_too_large' => 'El tamaño del archivo excede el límite máximo',
    'invalid_email_format' => 'Formato de email inválido',
    'password_too_weak' => 'La contraseña es demasiado débil',
    'username_taken' => 'El nombre de usuario ya está en uso',
    'email_already_registered' => 'El email ya está registrado',
    'invalid_token' => 'Token inválido o expirado',
    'rate_limit_exceeded' => 'Límite de velocidad excedido. Por favor, inténtalo de nuevo más tarde',

    // Errores de Base de Datos
    'database_connection_failed' => 'Falló la conexión a la base de datos',
    'database_query_failed' => 'Falló la consulta de base de datos',
    'database_transaction_failed' => 'Falló la transacción de base de datos',
    'database_constraint_violation' => 'Violación de restricción de base de datos',
    'database_deadlock' => 'Interbloqueo de base de datos detectado',
    'database_timeout' => 'Operación de base de datos agotó el tiempo de espera',

    // Errores de Red
    'network_unreachable' => 'La red no es accesible',
    'connection_refused' => 'La conexión fue rechazada',
    'connection_reset' => 'La conexión fue reiniciada',
    'connection_aborted' => 'La conexión fue abortada',
    'host_unreachable' => 'El host no es accesible',
    'dns_lookup_failed' => 'Falló la búsqueda DNS',

    // Errores de Seguridad
    'access_denied' => 'Acceso denegado',
    'forbidden_action' => 'Esta acción está prohibida',
    'insufficient_permissions' => 'Permisos insuficientes',
    'security_violation' => 'Violación de seguridad detectada',
    'suspicious_activity' => 'Actividad sospechosa detectada',
    'account_compromised' => 'Seguridad de la cuenta comprometida',

    // Errores de Formularios
    'form_validation_failed' => 'Falló la validación del formulario',
    'required_field_missing' => 'Falta el campo obligatorio',
    'invalid_field_value' => 'Valor de campo inválido',
    'field_too_short' => 'El valor del campo es demasiado corto',
    'field_too_long' => 'El valor del campo es demasiado largo',
    'field_format_invalid' => 'El formato del campo es inválido',
    'field_already_exists' => 'El valor del campo ya existe',
    'field_confirmation_mismatch' => 'La confirmación del campo no coincide',

    // Errores de Operaciones de Archivos
    'file_not_found' => 'Archivo no encontrado',
    'file_cannot_be_read' => 'El archivo no puede ser leído',
    'file_cannot_be_written' => 'El archivo no puede ser escrito',
    'file_cannot_be_deleted' => 'El archivo no puede ser eliminado',
    'file_cannot_be_moved' => 'El archivo no puede ser movido',
    'file_cannot_be_copied' => 'El archivo no puede ser copiado',
    'file_permission_denied' => 'Permiso de archivo denegado',
    'file_is_corrupted' => 'El archivo está corrupto',
    'file_is_empty' => 'El archivo está vacío',
    'file_type_not_supported' => 'El tipo de archivo no está soportado',

    // Errores de Email
    'email_send_failed' => 'Falló el envío del email',
    'email_invalid_recipient' => 'Destinatario de email inválido',
    'email_invalid_sender' => 'Remitente de email inválido',
    'email_template_not_found' => 'Plantilla de email no encontrada',
    'email_attachment_failed' => 'Falló la adjunción del archivo al email',
    'email_queue_failed' => 'Falló la cola del email',

    // Errores de Notificaciones
    'notification_send_failed' => 'Falló el envío de la notificación',
    'notification_template_not_found' => 'Plantilla de notificación no encontrada',
    'notification_channel_unavailable' => 'El canal de notificación no está disponible',
    'notification_recipient_invalid' => 'Destinatario de notificación inválido',

    // Errores de API
    'api_endpoint_not_found' => 'Endpoint de API no encontrado',
    'api_method_not_allowed' => 'Método de API no permitido',
    'api_authentication_failed' => 'Falló la autenticación de la API',
    'api_authorization_failed' => 'Falló la autorización de la API',
    'api_rate_limit_exceeded' => 'Límite de velocidad de API excedido',
    'api_invalid_request' => 'Solicitud de API inválida',
    'api_server_error' => 'Error del servidor de API',

    // Errores de Mantenimiento
    'maintenance_mode_active' => 'El modo de mantenimiento está activo',
    'maintenance_scheduled' => 'El mantenimiento está programado',
    'maintenance_estimated_duration' => 'Duración estimada del mantenimiento',
    'maintenance_reason' => 'Razón del mantenimiento',

    // Mensajes de Error Genéricos
    'unknown_error' => 'Ha ocurrido un error desconocido',
    'unhandled_exception' => 'Ha ocurrido una excepción no manejada',
    'internal_server_error' => 'Error interno del servidor',
    'service_unavailable' => 'El servicio no está disponible temporalmente',
    'bad_request' => 'Solicitud incorrecta',
    'not_found' => 'No encontrado',
    'method_not_allowed' => 'Método no permitido',
    'request_timeout' => 'Tiempo de espera de la solicitud',
    'conflict' => 'Ha ocurrido un conflicto',
    'gone' => 'El recurso ya no está disponible',
    'length_required' => 'Longitud requerida',
    'precondition_failed' => 'Falló la precondición',
    'payload_too_large' => 'Carga útil demasiado grande',
    'uri_too_long' => 'URI demasiado larga',
    'unsupported_media_type' => 'Tipo de medio no soportado',
    'range_not_satisfiable' => 'Rango no satisfacible',
    'expectation_failed' => 'Falló la expectativa',
    'im_a_teapot' => 'Soy una tetera',
    'misdirected_request' => 'Solicitud mal dirigida',
    'unprocessable_entity' => 'Entidad no procesable',
    'locked' => 'El recurso está bloqueado',
    'failed_dependency' => 'Dependencia fallida',
    'too_early' => 'Demasiado temprano',
    'upgrade_required' => 'Actualización requerida',
    'precondition_required' => 'Precondición requerida',
    'too_many_requests' => 'Demasiadas solicitudes',
    'request_header_fields_too_large' => 'Los campos del encabezado de la solicitud son demasiado grandes',
    'unavailable_for_legal_reasons' => 'No disponible por razones legales',
    'internal_server_error' => 'Error interno del servidor',
    'not_implemented' => 'No implementado',
    'bad_gateway' => 'Puerta de enlace incorrecta',
    'service_unavailable' => 'Servicio no disponible',
    'gateway_timeout' => 'Tiempo de espera de puerta de enlace',
    'http_version_not_supported' => 'Versión HTTP no soportada',
    'variant_also_negotiates' => 'La variante también negocia',
    'insufficient_storage' => 'Almacenamiento insuficiente',
    'loop_detected' => 'Bucle detectado',
    'not_extended' => 'No extendido',
    'network_authentication_required' => 'Autenticación de red requerida',
];
