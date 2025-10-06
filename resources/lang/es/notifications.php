<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for various notifications that
    | we need to display to the user. You are free to modify these language
    | lines according to your application's requirements.
    |
    */

    'welcome' => [
        'title' => '¡Bienvenido a :app_name!',
        'message' => 'Gracias por unirte. Estamos encantados de acompañarte en la gestión de tu negocio.',
        'action' => 'Empezar ahora',
    ],

    'password_reset' => [
        'title' => 'Solicitud de restablecimiento de contraseña',
        'message' => 'Hemos recibido una petición para restablecer la contraseña de tu cuenta.',
        'action' => 'Restablecer contraseña',
        'warning' => 'Si no lo has pedido tú, ignora este correo y tu contraseña seguirá siendo la misma.',
    ],

    'email_verification' => [
        'title' => 'Verifica tu correo electrónico',
        'message' => 'Solo falta confirmar tu dirección de correo para activar la cuenta.',
        'action' => 'Verificar correo',
        'warning' => 'Si no has creado una cuenta, puedes ignorar este mensaje.',
    ],

    'account_created' => [
        'title' => 'Cuenta creada',
        'message' => 'Tu cuenta en :app_name ya está lista.',
        'action' => 'Completar configuración',
    ],

    'login_alert' => [
        'title' => 'Nuevo inicio de sesión',
        'message' => 'Tu cuenta se ha usado para iniciar sesión desde :location con :device.',
        'action' => 'Ver actividad',
        'warning' => 'Si no reconoces el acceso, cambia la contraseña y revisa tu seguridad.',
    ],

    'security_alert' => [
        'title' => 'Aviso de seguridad',
        'message' => 'Hemos detectado actividad poco habitual en tu cuenta. Revísala y actúa si hace falta.',
        'action' => 'Revisar actividad',
        'warning' => 'Mensaje automático de seguridad.',
    ],

    'profile_updated' => [
        'title' => 'Perfil Actualizado',
        'message' => 'Ya hemos guardado los cambios en tu perfil.',
        'action' => 'Ver perfil',
    ],

    'settings_updated' => [
        'title' => 'Ajustes actualizados',
        'message' => 'Hemos guardado la nueva configuración de tu cuenta.',
        'action' => 'Ver ajustes',
    ],

    'two_factor_enabled' => [
        'title' => 'Autenticación en dos pasos activada',
        'message' => 'Tu cuenta ya tiene activada la autenticación en dos pasos.',
        'action' => 'Gestionar 2FA',
    ],

    'two_factor_disabled' => [
        'title' => 'Autenticación en dos pasos desactivada',
        'message' => 'Has desactivado la autenticación en dos pasos para tu cuenta.',
        'action' => 'Activar 2FA',
    ],

    'account_locked' => [
        'title' => 'Cuenta bloqueada temporalmente',
        'message' => 'Hemos bloqueado la cuenta tras varios intentos fallidos de acceso.',
        'action' => 'Desbloquear cuenta',
        'warning' => 'Es una medida preventiva para protegerte.',
    ],

    'account_unlocked' => [
        'title' => 'Cuenta desbloqueada',
        'message' => 'Tu cuenta vuelve a estar disponible. Ya puedes iniciar sesión.',
        'action' => 'Iniciar sesión',
    ],

];
