<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Líneas de Idioma para Notificaciones
    |--------------------------------------------------------------------------
    |
    | Notificaciones con un tono cercano y natural para el usuario
    |
    */

    'welcome' => [
        'title' => '¡Te damos la bienvenida a :app_name!',
        'message' => 'Estamos encantados de que te unas. Todo está listo para que empieces a gestionar tu negocio.',
        'action' => 'Comenzar',
    ],

    'password_reset' => [
        'title' => '¿Has olvidado tu contraseña?',
        'message' => 'Hemos recibido una solicitud para cambiar la contraseña de tu cuenta.',
        'action' => 'Crear nueva contraseña',
        'warning' => 'Si no has sido tú quien lo ha pedido, no te preocupes, puedes ignorar este mensaje.',
    ],

    'email_verification' => [
        'title' => 'Confirma tu correo electrónico',
        'message' => 'Solo nos falta verificar tu dirección de correo para activar tu cuenta.',
        'action' => 'Confirmar correo',
        'warning' => 'Si no has creado esta cuenta, puedes borrar este mensaje sin problema.',
    ],

    'account_created' => [
        'title' => '¡Cuenta lista!',
        'message' => 'Tu cuenta en :app_name ya está creada y preparada para usar.',
        'action' => 'Configurar cuenta',
    ],

    'login_alert' => [
        'title' => 'Nuevo acceso a tu cuenta',
        'message' => 'Alguien ha iniciado sesión en tu cuenta desde :location usando :device.',
        'action' => 'Revisar actividad',
        'warning' => 'Si no has sido tú, te recomendamos cambiar tu contraseña cuanto antes.',
    ],

    'security_alert' => [
        'title' => 'Actividad inusual detectada',
        'message' => 'Hemos visto movimientos extraños en tu cuenta. Échale un vistazo por si hay algo raro.',
        'action' => 'Comprobar seguridad',
        'warning' => 'Es un aviso automático de nuestro sistema de protección.',
    ],

    'profile_updated' => [
        'title' => 'Perfil actualizado',
        'message' => 'Los cambios en tu perfil se han guardado correctamente.',
        'action' => 'Ver mi perfil',
    ],

    'settings_updated' => [
        'title' => 'Ajustes guardados',
        'message' => 'Hemos actualizado la configuración de tu cuenta con los nuevos cambios.',
        'action' => 'Ver ajustes',
    ],

    'two_factor_enabled' => [
        'title' => 'Verificación en dos pasos activada',
        'message' => 'Ya tienes activada la verificación en dos pasos. Tu cuenta está más protegida.',
        'action' => 'Gestionar verificación',
    ],

    'two_factor_disabled' => [
        'title' => 'Verificación en dos pasos desactivada',
        'message' => 'Has desactivado la verificación en dos pasos de tu cuenta.',
        'action' => 'Activar verificación',
    ],

    'account_locked' => [
        'title' => 'Cuenta bloqueada por seguridad',
        'message' => 'Hemos bloqueado temporalmente tu cuenta después de varios intentos fallidos de acceso.',
        'action' => 'Recuperar cuenta',
        'warning' => 'Es una medida de protección automática para mantener tu cuenta segura.',
    ],

    'account_unlocked' => [
        'title' => 'Cuenta recuperada',
        'message' => 'Tu cuenta ya está desbloqueada y puedes volver a acceder con normalidad.',
        'action' => 'Entrar ahora',
    ],

    'payment_received' => [
        'title' => 'Pago confirmado',
        'message' => 'Hemos recibido tu pago correctamente. ¡Gracias!',
        'action' => 'Ver detalles',
    ],

    'trial_ending' => [
        'title' => 'Tu periodo de prueba termina pronto',
        'message' => 'Tu prueba gratuita finaliza en :days días. ¿Quieres continuar?',
        'action' => 'Elegir plan',
    ],

    'new_message' => [
        'title' => 'Tienes un nuevo mensaje',
        'message' => 'Has recibido un mensaje de :sender',
        'action' => 'Leer mensaje',
    ],

    'task_reminder' => [
        'title' => 'Recordatorio de tarea',
        'message' => 'Tienes una tarea pendiente: ":task"',
        'action' => 'Ver tarea',
    ],

];