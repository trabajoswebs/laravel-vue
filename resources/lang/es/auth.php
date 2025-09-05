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
        'title' => 'Iniciar sesión',
        'subtitle' => 'Accede a tu cuenta',
        'email' => 'Correo electrónico',
        'password' => 'La Contraseña',
        'remember' => 'Recordarme',
        'forgot' => '¿Has olvidado tu contraseña?',
        'submit' => 'Acceder',
        'no_account' => '¿No tienes cuenta?',
        'register' => 'Regístrate',
        'or' => 'o',
        'with_google' => 'Continuar con Google',
        'with_github' => 'Continuar con GitHub',
    ],

    'register' => [
        'title' => 'Registro',
        'subtitle' => 'Crea tu cuenta',
        'name' => 'Nombre completo',
        'username' => 'Nombre de usuario',
        'email' => 'Correo electrónico',
        'password' => 'LaContraseña',
        'password_confirmation' => 'Confirmar contraseña',
        'terms' => 'Acepto los :terms y la :privacy',
        'submit' => 'Crear cuenta',
        'have_account' => '¿Ya tienes cuenta?',
        'login' => 'Inicia sesión',
        'or' => 'o',
        'with_google' => 'Continuar con Google',
        'with_github' => 'Continuar con GitHub',
    ],

    'logout' => [
        'title' => 'Cerrar sesión',
        'message' => '¿Estás seguro de que quieres cerrar sesión?',
        'confirm' => 'Sí, cerrar sesión',
        'cancel' => 'Cancelar',
    ],

    'verification' => [
        'title' => 'Verificar dirección de correo',
        'message' => 'Antes de continuar, por favor revisa tu correo electrónico para obtener un enlace de verificación.',
        'resend' => 'Reenviar correo de verificación',
        'sent' => 'Se ha enviado un nuevo enlace de verificación a tu dirección de correo electrónico.',
        'verified' => 'Tu dirección de correo electrónico ha sido verificada correctamente.',
    ],

    'password' => [
        'reset' => [
            'title' => 'Restablecer contraseña',
            'subtitle' => 'Introduce tu dirección de correo electrónico y te enviaremos un enlace para restablecer tu contraseña.',
            'email' => 'Correo electrónico',
            'submit' => 'Enviar enlace de restablecimiento',
            'sent' => 'Hemos enviado por correo electrónico el enlace para restablecer tu contraseña.',
        ],
        'confirm' => [
            'title' => 'Confirmar contraseña',
            'subtitle' => 'Esta es una zona segura de la aplicación. Por favor, confirma tu contraseña antes de continuar.',
            'password' => 'Contraseña',
            'submit' => 'Confirmar',
        ],
        'update' => [
            'title' => 'Actualizar contraseña',
            'subtitle' => 'Asegúrate de que tu cuenta tenga una contraseña larga y segura para mantener la seguridad.',
            'current' => 'Contraseña actual',
            'new' => 'Nueva contraseña',
            'confirm' => 'Confirmar nueva contraseña',
            'submit' => 'Actualizar contraseña',
            'updated' => 'Tu contraseña se ha actualizado correctamente.',
        ],
    ],

    'profile' => [
        'title' => 'Perfil',
        'subtitle' => 'Actualiza la información de tu perfil de cuenta y dirección de correo electrónico.',
        'photo' => 'Foto de perfil',
        'remove_photo' => 'Eliminar foto',
        'select_photo' => 'Seleccionar nueva foto',
        'personal_info' => 'Datos personales',
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'save' => 'Guardar',
        'saved' => 'Guardado',
        'delete_account' => 'Eliminar cuenta',
        'delete_warning' => 'Una vez que elimines tu cuenta, todos sus recursos y datos se eliminarán permanentemente. Antes de eliminar tu cuenta, por favor descarga cualquier dato o información que quieras conservar.',
        'delete_confirm' => '¿Estás seguro de que quieres eliminar tu cuenta? Una vez que elimines tu cuenta, todos sus recursos y datos se eliminarán permanentemente. Por favor, introduce tu contraseña para confirmar que quieres eliminar tu cuenta de forma definitiva.',
        'delete_password' => 'Contraseña',
        'delete_submit' => 'Eliminar cuenta',
    ],

    'two_factor' => [
        'title' => 'Autenticación de dos factores',
        'subtitle' => 'Añade seguridad adicional a tu cuenta utilizando la autenticación de dos factores.',
        'enabled' => 'Has habilitado la autenticación de dos factores.',
        'disabled' => 'No has habilitado la autenticación de dos factores.',
        'description' => 'Cuando la autenticación de dos factores está habilitada, se te pedirá un token seguro y aleatorio durante la autenticación. Puedes obtener este token desde la aplicación Google Authenticator de tu teléfono.',
        'enable' => 'Habilitar',
        'disable' => 'Deshabilitar',
        'regenerate' => 'Regenerar códigos de recuperación',
        'show_recovery_codes' => 'Mostrar códigos de recuperación',
        'recovery_codes' => 'Guarda estos códigos de recuperación en un gestor de contraseñas seguro. Pueden utilizarse para recuperar el acceso a tu cuenta si pierdes tu dispositivo de autenticación de dos factores.',
        'confirm' => 'Por favor, confirma el acceso a tu cuenta introduciendo el código de autenticación proporcionado por tu aplicación autenticadora.',
        'code' => 'Código',
        'submit' => 'Confirmar',
    ],

];
