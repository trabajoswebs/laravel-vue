<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Líneas de Idioma para Errores
    |--------------------------------------------------------------------------
    | Mensajes más humanos y accionables. Sin rodeos.
    */

    // Códigos de Error HTTP
    '400' => [
        'title' => 'Solicitud no válida',
        'message' => 'No pudimos entender lo que pediste.',
        'description' => 'Revisa la URL o el formulario y vuelve a intentarlo. Si persiste, contacta con soporte.',
    ],
    '401' => [
        'title' => 'Necesitas iniciar sesión',
        'message' => 'Este contenido requiere una cuenta.',
        'description' => 'Inicia sesión con tus credenciales. Si no tienes cuenta, regístrate o pide acceso.',
    ],
    '403' => [
        'title' => 'Acceso denegado',
        'message' => 'No tienes permisos para ver esto.',
        'description' => 'Si crees que es un error, solicita permisos al administrador o contacta con soporte.',
    ],
    '404' => [
        'title' => 'No encontramos lo que buscas',
        'message' => 'La página no existe o cambió de lugar.',
        'description' => 'Comprueba la dirección o vuelve al inicio. Si llegaste desde un enlace, puede estar desactualizado.',
    ],
    '405' => [
        'title' => 'Método no permitido',
        'message' => 'Esta página no admite la acción realizada.',
        'description' => 'Vuelve atrás y usa los botones o enlaces de la página para repetir la acción.',
    ],
    '408' => [
        'title' => 'Se agotó el tiempo',
        'message' => 'La solicitud tardó demasiado.',
        'description' => 'Comprueba tu conexión y prueba de nuevo. Si sigue ocurriendo, inténtalo más tarde.',
    ],
    '422' => [
        'title' => 'No pudimos procesar los datos',
        'message' => 'Hay información que necesitamos corregir.',
        'description' => 'Revisa los campos marcados y corrige los errores antes de enviar de nuevo.',
    ],
    '429' => [
        'title' => 'Demasiadas solicitudes',
        'message' => 'Has ido muy rápido.',
        'description' => 'Espera unos segundos y vuelve a intentarlo. Si el problema continúa, reduce el ritmo de peticiones.',
    ],
    '500' => [
        'title' => 'Ha ocurrido un problema',
        'message' => 'Algo falló en nuestro servidor.',
        'description' => 'Estamos trabajando para solucionarlo. Prueba de nuevo en unos minutos.',
    ],
    '502' => [
        'title' => 'Error de conexión con un servicio',
        'message' => 'Recibimos una respuesta inválida de un servicio externo.',
        'description' => 'Suele ser temporal. Inténtalo más tarde.',
    ],
    '503' => [
        'title' => 'Estamos en mantenimiento',
        'message' => 'El servicio no está disponible ahora mismo.',
        'description' => 'Volveremos a estar operativos en breve. Gracias por tu paciencia.',
    ],
    '504' => [
        'title' => 'La respuesta tardó demasiado',
        'message' => 'Un servicio no respondió a tiempo.',
        'description' => 'Reintenta en unos minutos. Si persiste, avísanos.',
    ],

    // Errores de Aplicación
    'general_error' => 'Hemos tenido un problema. Intenta de nuevo.',
    'unexpected_error' => 'Esto no debería haber pasado. Estamos revisándolo.',
    'system_error' => 'Error del sistema. Vuelve a intentarlo en breve.',
    'database_error' => 'No pudimos completar la operación en la base de datos.',
    'connection_error' => 'No se pudo establecer la conexión necesaria.',
    'authentication_error' => 'No pudimos verificar tu identidad. Inicia sesión de nuevo.',
    'authorization_error' => 'No tienes permisos para realizar esta acción.',
    'validation_error' => 'Hay datos que necesitamos corregir antes de continuar.',
    'file_error' => 'Hubo un problema con el archivo.',
    'upload_error' => 'No pudimos subir el archivo. Prueba otra vez.',
    'download_error' => 'No pudimos descargar el archivo. Inténtalo más tarde.',
    'email_error' => 'No pudimos enviar el correo en este momento.',
    'notification_error' => 'No pudimos enviar la notificación.',

    // Mensajes de Error Amigables para el Usuario
    'something_went_wrong' => 'Algo no ha salido como esperábamos.',
    'try_again_later' => 'Prueba de nuevo en unos minutos.',
    'contact_support' => 'Si sigue ocurriendo, contacta con soporte.',
    'check_your_input' => 'Revisa los datos del formulario y corrige lo necesario.',
    'refresh_page' => 'Recarga la página y vuelve a intentarlo.',
    'clear_browser_cache' => 'Borra la caché del navegador e inténtalo otra vez.',
    'check_internet_connection' => 'Comprueba tu conexión a Internet.',
    'try_different_browser' => 'Prueba con otro navegador.',
    'restart_application' => 'Cierra y vuelve a abrir la aplicación.',

    // Mensajes de Error Específicos
    'invalid_credentials' => 'Usuario o contraseña incorrectos.',
    'account_locked' => 'Tu cuenta está bloqueada temporalmente por seguridad.',
    'account_disabled' => 'Tu cuenta está deshabilitada. Contacta con soporte.',
    'session_expired' => 'Tu sesión caducó. Inicia sesión de nuevo.',
    'permission_denied' => 'No tienes permiso para esta acción.',
    'resource_not_found' => 'No encontramos el recurso solicitado.',
    'resource_already_exists' => 'Este recurso ya existe.',
    'resource_in_use' => 'No se puede completar la acción: el recurso está en uso.',
    'invalid_file_format' => 'El formato del archivo no es compatible.',
    'file_too_large' => 'El archivo supera el tamaño permitido.',
    'invalid_email_format' => 'El email no tiene un formato válido.',
    'password_too_weak' => 'La contraseña es demasiado débil. Usa más caracteres y combina letras, números y símbolos.',
    'username_taken' => 'Ese nombre de usuario ya está en uso.',
    'email_already_registered' => 'Ese email ya está registrado.',
    'invalid_token' => 'El enlace o token no es válido o ha expirado.',
    'rate_limit_exceeded' => 'Has alcanzado el límite de intentos. Espera un momento y vuelve a probar.',

    // Errores de Base de Datos
    'database_connection_failed' => 'No podemos conectar con la base de datos ahora mismo.',
    'database_query_failed' => 'No pudimos completar la operación en la base de datos.',
    'database_transaction_failed' => 'La operación no pudo confirmarse. No se han aplicado cambios.',
    'database_constraint_violation' => 'La operación entra en conflicto con datos existentes.',
    'database_deadlock' => 'Se detectó un bloqueo de base de datos. Intenta de nuevo.',
    'database_timeout' => 'La base de datos tardó demasiado en responder.',

    // Errores de Red
    'network_unreachable' => 'No hay conexión de red.',
    'connection_refused' => 'La conexión fue rechazada por el servidor.',
    'connection_reset' => 'La conexión se interrumpió.',
    'connection_aborted' => 'La conexión se canceló.',
    'host_unreachable' => 'No se puede acceder al servidor.',
    'dns_lookup_failed' => 'No pudimos encontrar el servidor (DNS).',

    // Errores de Seguridad
    'access_denied' => 'Acceso denegado por seguridad.',
    'forbidden_action' => 'Esta acción no está permitida.',
    'insufficient_permissions' => 'No tienes permisos suficientes.',
    'security_violation' => 'Detectamos una acción no permitida.',
    'suspicious_activity' => 'Detectamos actividad inusual. Hemos bloqueado la acción por seguridad.',
    'account_compromised' => 'Hemos detectado actividad sospechosa en tu cuenta. Cambia tu contraseña.',

    // Errores de Formularios
    'form_validation_failed' => 'Revisa los campos marcados y corrige los errores.',
    'required_field_missing' => 'Este campo es obligatorio.',
    'invalid_field_value' => 'El valor introducido no es válido.',
    'field_too_short' => 'El valor es demasiado corto.',
    'field_too_long' => 'El valor es demasiado largo.',
    'field_format_invalid' => 'El formato no es correcto.',
    'field_already_exists' => 'Este valor ya está en uso.',
    'field_confirmation_mismatch' => 'La confirmación no coincide.',

    // Operaciones de Archivos
    'file_not_found' => 'No encontramos el archivo.',
    'file_cannot_be_read' => 'No se puede leer el archivo.',
    'file_cannot_be_written' => 'No se puede guardar el archivo.',
    'file_cannot_be_deleted' => 'No pudimos eliminar el archivo.',
    'file_cannot_be_moved' => 'No pudimos mover el archivo.',
    'file_cannot_be_copied' => 'No pudimos copiar el archivo.',
    'file_permission_denied' => 'No tienes permisos para esta operación con el archivo.',
    'file_is_corrupted' => 'El archivo está dañado.',
    'file_is_empty' => 'El archivo está vacío.',
    'file_type_not_supported' => 'Este tipo de archivo no es compatible.',

    // Errores de Email
    'email_send_failed' => 'No pudimos enviar el correo ahora mismo.',
    'email_invalid_recipient' => 'La dirección del destinatario no es válida.',
    'email_invalid_sender' => 'La dirección del remitente no es válida.',
    'email_template_not_found' => 'No encontramos la plantilla de correo.',
    'email_attachment_failed' => 'No pudimos adjuntar el archivo al correo.',
    'email_queue_failed' => 'No pudimos encolar el correo para envío.',

    // Errores de Notificaciones
    'notification_send_failed' => 'No pudimos enviar la notificación.',
    'notification_template_not_found' => 'No encontramos la plantilla de notificación.',
    'notification_channel_unavailable' => 'El canal de notificación no está disponible.',
    'notification_recipient_invalid' => 'El destinatario de la notificación no es válido.',

    // Errores de API
    'api_endpoint_not_found' => 'No encontramos el endpoint solicitado.',
    'api_method_not_allowed' => 'Ese método no está permitido en este endpoint.',
    'api_authentication_failed' => 'No pudimos autenticar la petición.',
    'api_authorization_failed' => 'No tienes permisos para esta petición.',
    'api_rate_limit_exceeded' => 'Has superado el límite de peticiones. Espera y vuelve a intentarlo.',
    'api_invalid_request' => 'La petición enviada a la API no es válida.',
    'api_server_error' => 'La API tuvo un problema al procesar tu petición.',

    // Mantenimiento
    'maintenance_mode_active' => 'Estamos realizando tareas de mantenimiento.',
    'maintenance_scheduled' => 'Tenemos mantenimiento programado.',
    'maintenance_estimated_duration' => 'Duración estimada del mantenimiento.',
    'maintenance_reason' => 'Motivo del mantenimiento.',

    // Mensajes Genéricos (HTTP varios)
    'unknown_error' => 'Ha ocurrido un error desconocido.',
    'unhandled_exception' => 'Se produjo una excepción no controlada.',
    'internal_server_error' => 'Error interno del servidor.',
    'service_unavailable' => 'Servicio no disponible temporalmente.',
    'bad_request' => 'Solicitud incorrecta.',
    'not_found' => 'No encontrado.',
    'method_not_allowed' => 'Método no permitido.',
    'request_timeout' => 'Tiempo de espera agotado.',
    'conflict' => 'No pudimos completar la acción por un conflicto.',
    'gone' => 'El recurso ya no está disponible.',
    'length_required' => 'Falta indicar la longitud del contenido.',
    'precondition_failed' => 'No se cumple una precondición requerida.',
    'payload_too_large' => 'El contenido es demasiado grande.',
    'uri_too_long' => 'La dirección (URL) es demasiado larga.',
    'unsupported_media_type' => 'El tipo de contenido no es compatible.',
    'range_not_satisfiable' => 'El rango solicitado no es válido.',
    'expectation_failed' => 'No se pudo cumplir la expectativa de la petición.',
    'im_a_teapot' => 'Sí, somos una tetera (código 418). Prueba otra ruta ☕️.',
    'misdirected_request' => 'La petición se envió al servidor equivocado.',
    'unprocessable_entity' => 'Entidad no procesable.',
    'locked' => 'El recurso está bloqueado.',
    'failed_dependency' => 'No se pudo completar por una dependencia fallida.',
    'too_early' => 'Demasiado pronto para procesar esta petición.',
    'upgrade_required' => 'Necesitas actualizar el cliente para continuar.',
    'precondition_required' => 'Falta una precondición en la petición.',
    'too_many_requests' => 'Demasiadas solicitudes.',
    'request_header_fields_too_large' => 'Los encabezados de la petición son demasiado grandes.',
    'unavailable_for_legal_reasons' => 'No disponible por razones legales.',
    'not_implemented' => 'Funcionalidad no implementada.',
    'bad_gateway' => 'Puerta de enlace incorrecta.',
    'gateway_timeout' => 'Tiempo de espera de la puerta de enlace.',
    'http_version_not_supported' => 'Versión de HTTP no soportada.',
    'variant_also_negotiates' => 'Negociación de contenido en conflicto.',
    'insufficient_storage' => 'No hay espacio suficiente para completar la operación.',
    'loop_detected' => 'Se detectó un bucle.',
    'not_extended' => 'La petición requiere extensiones no soportadas.',
    'network_authentication_required' => 'Se requiere autenticación de red.',
];
