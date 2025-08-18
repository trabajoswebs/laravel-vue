<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña proporcionada es incorrecta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Por favor, inténtalo de nuevo en :seconds segundos.',

    'login' => [
        'title' => 'Iniciar Sesión',
        'subtitle' => 'Accede a tu cuenta',
        'email' => 'Correo Electrónico',
        'password' => 'Contraseña',
        'remember' => 'Recordarme',
        'forgot' => '¿Olvidaste tu contraseña?',
        'submit' => 'Acceder',
        'no_account' => '¿No tienes una cuenta?',
        'register' => 'Regístrate',
        'or' => 'o',
        'with_google' => 'Continuar con Google',
        'with_github' => 'Continuar con GitHub',
    ],

    'register' => [
        'title' => 'Registro',
        'subtitle' => 'Crea tu cuenta',
        'name' => 'Nombre Completo',
        'username' => 'Nombre de Usuario',
        'email' => 'Correo Electrónico',
        'password' => 'Contraseña',
        'password_confirmation' => 'Confirmar Contraseña',
        'terms' => 'Acepto los :terms y la :privacy',
        'submit' => 'Crear Cuenta',
        'have_account' => '¿Ya tienes una cuenta?',
        'login' => 'Inicia sesión',
        'or' => 'o',
        'with_google' => 'Continuar con Google',
        'with_github' => 'Continuar con GitHub',
    ],

    'logout' => [
        'title' => 'Cerrar Sesión',
        'message' => '¿Estás seguro de que quieres cerrar sesión?',
        'confirm' => 'Sí, Cerrar Sesión',
        'cancel' => 'Cancelar',
    ],

    'verification' => [
        'title' => 'Verificar Dirección de Correo',
        'message' => 'Antes de continuar, por favor revisa tu correo electrónico para obtener un enlace de verificación.',
        'resend' => 'Reenviar Correo de Verificación',
        'sent' => 'Se ha enviado un nuevo enlace de verificación a tu dirección de correo electrónico.',
        'verified' => 'Tu dirección de correo electrónico ha sido verificada correctamente.',
    ],

    'password' => [
        'reset' => [
            'title' => 'Restablecer Contraseña',
            'subtitle' => 'Introduce tu dirección de correo electrónico y te enviaremos un enlace para restablecer tu contraseña.',
            'email' => 'Correo Electrónico',
            'submit' => 'Enviar Enlace de Restablecimiento',
            'sent' => 'Hemos enviado por correo electrónico el enlace para restablecer tu contraseña.',
        ],
        'confirm' => [
            'title' => 'Confirmar Contraseña',
            'subtitle' => 'Esta es un área segura de la aplicación. Por favor, confirma tu contraseña antes de continuar.',
            'password' => 'Contraseña',
            'submit' => 'Confirmar',
        ],
        'update' => [
            'title' => 'Actualizar Contraseña',
            'subtitle' => 'Asegúrate de que tu cuenta utilice una contraseña larga y aleatoria para mantener la seguridad.',
            'current' => 'Contraseña Actual',
            'new' => 'Nueva Contraseña',
            'confirm' => 'Confirmar Nueva Contraseña',
            'submit' => 'Actualizar Contraseña',
            'updated' => 'Tu contraseña se ha actualizado correctamente.',
        ],
    ],

    'profile' => [
        'title' => 'Perfil',
        'subtitle' => 'Actualiza la información de tu perfil de cuenta y dirección de correo electrónico.',
        'photo' => 'Foto de Perfil',
        'remove_photo' => 'Eliminar Foto',
        'select_photo' => 'Seleccionar Nueva Foto',
        'personal_info' => 'Información Personal',
        'name' => 'Nombre',
        'email' => 'Correo Electrónico',
        'save' => 'Guardar',
        'saved' => 'Guardado.',
        'delete_account' => 'Eliminar Cuenta',
        'delete_warning' => 'Una vez que tu cuenta sea eliminada, todos sus recursos y datos se eliminarán permanentemente. Antes de eliminar tu cuenta, por favor descarga cualquier dato o información que desees conservar.',
        'delete_confirm' => '¿Estás seguro de que quieres eliminar tu cuenta? Una vez que tu cuenta sea eliminada, todos sus recursos y datos se eliminarán permanentemente. Por favor, introduce tu contraseña para confirmar que deseas eliminar permanentemente tu cuenta.',
        'delete_password' => 'Contraseña',
        'delete_submit' => 'Eliminar Cuenta',
    ],

    'two_factor' => [
        'title' => 'Autenticación de Dos Factores',
        'subtitle' => 'Añade seguridad adicional a tu cuenta utilizando la autenticación de dos factores.',
        'enabled' => 'Has habilitado la autenticación de dos factores.',
        'disabled' => 'No has habilitado la autenticación de dos factores.',
        'description' => 'Cuando la autenticación de dos factores está habilitada, se te pedirá un token seguro y aleatorio durante la autenticación. Puedes obtener este token desde la aplicación Google Authenticator de tu teléfono.',
        'enable' => 'Habilitar',
        'disable' => 'Deshabilitar',
        'regenerate' => 'Regenerar Códigos de Recuperación',
        'show_recovery_codes' => 'Mostrar Códigos de Recuperación',
        'recovery_codes' => 'Guarda estos códigos de recuperación en un gestor de contraseñas seguro. Pueden utilizarse para recuperar el acceso a tu cuenta si pierdes tu dispositivo de autenticación de dos factores.',
        'confirm' => 'Por favor, confirma el acceso a tu cuenta introduciendo el código de autenticación proporcionado por tu aplicación autenticadora.',
        'code' => 'Código',
        'submit' => 'Confirmar',
    ],

];
