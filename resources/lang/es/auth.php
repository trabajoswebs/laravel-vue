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

    'failed' => 'No hemos encontrado ninguna cuenta con esos datos.',
    'password' => 'La contraseña que has introducido no es correcta.',
    'throttle' => 'Has superado el número de intentos permitidos. Espera :seconds segundos e inténtalo de nuevo.',

    'registration_successful' => 'Hemos creado tu cuenta. Revisa el correo y confirma tu dirección.',
    'registration_failed' => 'No hemos podido completar el registro. Comprueba los datos e inténtalo de nuevo en un momento.',

    'login' => [
        'title' => 'Iniciar sesión',
        'subtitle' => 'Accede a tu cuenta',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'remember' => 'Recordarme',
        'forgot' => '¿Has olvidado tu contraseña?',
        'submit' => 'Entrar',
        'no_account' => '¿Todavía no tienes cuenta?',
        'register' => 'Crear cuenta',
        'or' => 'o',
        'with_google' => 'Continuar con Google',
        'with_github' => 'Continuar con GitHub',
    ],

    'register' => [
        'title' => 'Registro',
        'subtitle' => 'Crea tu cuenta',
        'name' => 'Nombre y apellidos',
        'username' => 'Nombre de usuario',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
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
        'message' => '¿Segura/o de que quieres salir de la cuenta?',
        'confirm' => 'Sí, cerrar sesión',
        'cancel' => 'Cancelar',
    ],

    'verification' => [
        'title' => 'Verifica tu correo',
        'message' => 'Antes de continuar, revisa tu bandeja de entrada y sigue el enlace de verificación.',
        'resend' => 'Volver a enviar el correo',
        'sent' => 'Te hemos enviado un nuevo enlace de verificación a tu correo.',
        'verified' => 'Tu correo ha quedado verificado correctamente.',
    ],

    'password' => [
        'reset' => [
            'title' => 'Restablecer contraseña',
            'subtitle' => 'Introduce el correo con el que te registraste y te mandaremos un enlace.',
            'email' => 'Correo electrónico',
            'submit' => 'Enviar enlace',
            'sent' => 'Si la cuenta existe, te acabamos de enviar un enlace para que restablezcas la contraseña.',
            'link' => 'Si la cuenta existe, recibirás un enlace de restablecimiento en tu correo.',
        ],
        'confirm' => [
            'title' => 'Confirmar contraseña',
            'subtitle' => 'Por seguridad, necesitamos que confirmes la contraseña antes de seguir.',
            'password' => 'Contraseña',
            'submit' => 'Confirmar',
        ],
        'update' => [
            'title' => 'Actualizar contraseña',
            'subtitle' => 'Mantén tu cuenta protegida con una contraseña segura y solo para este servicio.',
            'current' => 'Contraseña actual',
            'new' => 'Nueva contraseña',
            'confirm' => 'Confirmar nueva contraseña',
            'submit' => 'Actualizar contraseña',
            'updated' => 'Hemos actualizado tu contraseña.',
        ],
    ],

    'profile' => [
        'title' => 'Perfil',
        'subtitle' => 'Revisa y actualiza tus datos personales y de contacto.',
        'photo' => 'Foto de perfil',
        'remove_photo' => 'Eliminar foto',
        'select_photo' => 'Seleccionar nueva foto',
        'personal_info' => 'Datos personales',
        'name' => 'Nombre',
        'email' => 'Correo electrónico',
        'save' => 'Guardar',
        'saved' => 'Guardado',
        'delete_account' => 'Eliminar cuenta',
        'delete_warning' => 'Si eliminas tu cuenta, borraremos todos los datos de forma permanente. Descarga lo que necesites antes de continuar.',
        'delete_confirm' => 'Introduce tu contraseña si quieres borrar tu cuenta definitivamente.',
        'delete_password' => 'Contraseña',
        'delete_submit' => 'Eliminar cuenta',
    ],

    'update' => [
        'failed' => 'No hemos podido actualizar el perfil. Inténtalo de nuevo en un momento.',
        'success' => 'Listo, tus cambios están guardados.'
    ],

    'deleted' => [
        'failed' => 'No hemos podido eliminar la cuenta. Inténtalo de nuevo en un momento.',
        'success' => 'Tu cuenta ha sido eliminada correctamente. Esperamos verte de nuevo pronto.',
    ],

    'two_factor' => [
        'title' => 'Autenticación en dos pasos',
        'subtitle' => 'Añade una capa extra de seguridad a tu cuenta.',
        'enabled' => 'La autenticación en dos pasos está activada.',
        'disabled' => 'La autenticación en dos pasos está desactivada.',
        'description' => 'Al activarla, pediremos un código temporal además de tu contraseña. Lo generarás con tu app autenticadora (por ejemplo, Google Authenticator).',
        'enable' => 'Activar',
        'disable' => 'Desactivar',
        'regenerate' => 'Generar nuevos códigos de recuperación',
        'show_recovery_codes' => 'Ver códigos de recuperación',
        'recovery_codes' => 'Guarda estos códigos en un lugar seguro. Te permitirán entrar si pierdes tu móvil.',
        'confirm' => 'Introduce el código que aparece en tu app autenticadora para confirmar.',
        'code' => 'Código',
        'submit' => 'Confirmar',
    ],

];
