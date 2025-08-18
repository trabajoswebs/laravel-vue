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
        'message' => 'Gracias por unirte a nosotros. ¡Nos emociona tenerte a bordo!',
        'action' => 'Comenzar',
    ],

    'password_reset' => [
        'title' => 'Solicitud de Restablecimiento de Contraseña',
        'message' => 'Estás recibiendo esta notificación porque hemos recibido una solicitud de restablecimiento de contraseña para tu cuenta.',
        'action' => 'Restablecer Contraseña',
        'warning' => 'Si no solicitaste un restablecimiento de contraseña, no es necesario realizar ninguna acción adicional.',
    ],

    'email_verification' => [
        'title' => 'Verifica tu Dirección de Correo',
        'message' => 'Por favor, verifica tu dirección de correo electrónico para completar tu registro.',
        'action' => 'Verificar Correo',
        'warning' => 'Si no creaste una cuenta, no es necesario realizar ninguna acción adicional.',
    ],

    'account_created' => [
        'title' => 'Cuenta Creada Exitosamente',
        'message' => 'Tu cuenta ha sido creada exitosamente. ¡Bienvenido a :app_name!',
        'action' => 'Completar Configuración',
    ],

    'login_alert' => [
        'title' => 'Nuevo Inicio de Sesión Detectado',
        'message' => 'Hemos detectado un nuevo inicio de sesión en tu cuenta desde :location en :device.',
        'action' => 'Ver Actividad',
        'warning' => 'Si no fuiste tú, por favor asegura tu cuenta inmediatamente.',
    ],

    'security_alert' => [
        'title' => 'Alerta de Seguridad',
        'message' => 'Hemos detectado actividad sospechosa en tu cuenta. Por favor, revisa y toma medidas si es necesario.',
        'action' => 'Revisar Actividad',
        'warning' => 'Esta es una notificación automática de seguridad.',
    ],

    'profile_updated' => [
        'title' => 'Perfil Actualizado',
        'message' => 'Tu perfil ha sido actualizado exitosamente.',
        'action' => 'Ver Perfil',
    ],

    'settings_updated' => [
        'title' => 'Configuración Actualizada',
        'message' => 'La configuración de tu cuenta ha sido actualizada exitosamente.',
        'action' => 'Ver Configuración',
    ],

    'two_factor_enabled' => [
        'title' => 'Autenticación de Dos Factores Habilitada',
        'message' => 'La autenticación de dos factores ha sido habilitada para tu cuenta.',
        'action' => 'Gestionar 2FA',
    ],

    'two_factor_disabled' => [
        'title' => 'Autenticación de Dos Factores Deshabilitada',
        'message' => 'La autenticación de dos factores ha sido deshabilitada para tu cuenta.',
        'action' => 'Habilitar 2FA',
    ],

    'account_locked' => [
        'title' => 'Cuenta Bloqueada Temporalmente',
        'message' => 'Tu cuenta ha sido bloqueada temporalmente debido a múltiples intentos fallidos de inicio de sesión.',
        'action' => 'Desbloquear Cuenta',
        'warning' => 'Esta es una medida de seguridad para proteger tu cuenta.',
    ],

    'account_unlocked' => [
        'title' => 'Cuenta Desbloqueada',
        'message' => 'Tu cuenta ha sido desbloqueada exitosamente. Ahora puedes iniciar sesión.',
        'action' => 'Iniciar Sesión',
    ],

];
