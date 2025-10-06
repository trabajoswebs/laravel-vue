<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Líneas de Idioma para Confirmaciones
    |--------------------------------------------------------------------------
    |
    | Las siguientes líneas de idioma se utilizan para mensajes de confirmación
    | que aparecen en toda la interfaz de la aplicación cuando los usuarios
    | necesitan confirmar acciones.
    |
    */

    // Confirmaciones Generales
    'confirm_action' => '¿Seguro que quieres realizar esta acción?',
    'confirm_continue' => '¿Seguro que quieres continuar?',
    'confirm_proceed' => '¿Seguro que quieres proceder?',
    'confirm_submit' => '¿Seguro que quieres enviar este formulario?',
    'confirm_save' => '¿Seguro que quieres guardar estos cambios?',
    'confirm_update' => '¿Seguro que quieres actualizar este elemento?',
    'confirm_create' => '¿Seguro que quieres crear este elemento?',

    // Confirmaciones de Eliminación
    'confirm_delete' => '¿Seguro que quieres eliminar este elemento?',
    'confirm_delete_selected' => '¿Seguro que quieres eliminar los elementos seleccionados?',
    'confirm_delete_all' => '¿Seguro que quieres eliminar todos los elementos?',
    'confirm_delete_permanent' => '¿Seguro que quieres eliminar permanentemente este elemento? Esta acción no se puede deshacer.',
    'confirm_delete_multiple' => '¿Seguro que quieres eliminar :count elementos?',
    'confirm_delete_user' => '¿Seguro que quieres eliminar este usuario?',
    'confirm_delete_role' => '¿Seguro que quieres eliminar este rol?',
    'confirm_delete_permission' => '¿Seguro que quieres eliminar este permiso?',
    'confirm_delete_file' => '¿Seguro que quieres eliminar este archivo?',
    'confirm_delete_files' => '¿Seguro que quieres eliminar estos archivos?',
    'confirm_delete_backup' => '¿Seguro que quieres eliminar esta copia de seguridad?',
    'confirm_delete_log' => '¿Seguro que quieres eliminar este log?',
    'confirm_delete_cache' => '¿Seguro que quieres limpiar la caché?',
    'confirm_delete_sessions' => '¿Seguro que quieres limpiar todas las sesiones?',

    // Confirmaciones de Cuenta y Perfil
    'confirm_logout' => '¿Seguro que quieres cerrar sesión?',
    'confirm_change_password' => '¿Seguro que quieres cambiar tu contraseña?',
    'confirm_delete_account' => '¿Seguro que quieres eliminar tu cuenta? Esta acción no se puede deshacer.',
    'confirm_deactivate_account' => '¿Seguro que quieres desactivar tu cuenta?',
    'confirm_reactivate_account' => '¿Seguro que quieres reactivar tu cuenta?',
    'confirm_suspend_account' => '¿Seguro que quieres suspender esta cuenta?',
    'confirm_unsuspend_account' => '¿Seguro que quieres rehabilitar esta cuenta?',
    'confirm_lock_account' => '¿Seguro que quieres bloquear esta cuenta?',
    'confirm_unlock_account' => '¿Seguro que quieres desbloquear esta cuenta?',
    'confirm_verify_email' => '¿Seguro que quieres enviar un email de verificación?',

    // Confirmaciones de Seguridad
    'confirm_enable_2fa' => '¿Seguro que quieres habilitar la autenticación de dos factores?',
    'confirm_disable_2fa' => '¿Seguro que quieres deshabilitar la autenticación de dos factores?',
    'confirm_generate_backup_codes' => '¿Seguro que quieres generar nuevos códigos de respaldo? Esto invalidará tus códigos antiguos.',
    'confirm_revoke_api_key' => '¿Seguro que quieres revocar esta clave de API? Esta acción no se puede deshacer.',
    'confirm_block_ip' => '¿Seguro que quieres bloquear esta dirección IP?',
    'confirm_unblock_ip' => '¿Seguro que quieres desbloquear esta dirección IP?',
    'confirm_whitelist_ip' => '¿Seguro que quieres añadir esta dirección IP a la lista blanca?',
    'confirm_blacklist_ip' => '¿Seguro que quieres añadir esta dirección IP a la lista negra?',
    'confirm_scan_security' => '¿Seguro que quieres ejecutar un escaneo de seguridad? Esto puede tardar varios minutos.',

    // Confirmaciones de Archivos y Subidas
    'confirm_upload_file' => '¿Seguro que quieres subir este archivo?',
    'confirm_upload_files' => '¿Seguro que quieres subir estos archivos?',
    'confirm_replace_file' => '¿Seguro que quieres reemplazar este archivo? El archivo antiguo será eliminado.',
    'confirm_move_file' => '¿Seguro que quieres mover este archivo?',
    'confirm_copy_file' => '¿Seguro que quieres copiar este archivo?',
    'confirm_rename_file' => '¿Seguro que quieres renombrar este archivo?',
    'confirm_download_file' => '¿Seguro que quieres descargar este archivo?',
    'confirm_export_data' => '¿Seguro que quieres exportar estos datos?',
    'confirm_import_data' => '¿Seguro que quieres importar estos datos? Esto puede sobrescribir datos existentes.',

    // Confirmaciones de Sistema y Mantenimiento
    'confirm_maintenance_mode' => '¿Seguro que quieres habilitar el modo de mantenimiento? Los usuarios no podrán acceder a la aplicación.',
    'confirm_disable_maintenance' => '¿Seguro que quieres deshabilitar el modo de mantenimiento?',
    'confirm_restart_system' => '¿Seguro que quieres reiniciar el sistema? Esto causará tiempo de inactividad temporal.',
    'confirm_shutdown_system' => '¿Seguro que quieres apagar el sistema? Esto causará tiempo de inactividad completo.',
    'confirm_backup_system' => '¿Seguro que quieres crear una copia de seguridad del sistema? Esto puede tardar varios minutos.',
    'confirm_restore_system' => '¿Seguro que quieres restaurar el sistema desde la copia de seguridad? Esto sobrescribirá los datos actuales.',
    'confirm_optimize_system' => '¿Seguro que quieres optimizar el sistema? Esto puede tardar varios minutos.',
    'confirm_clean_system' => '¿Seguro que quieres limpiar el sistema? Esto eliminará archivos temporales.',
    'confirm_update_system' => '¿Seguro que quieres actualizar el sistema? Esto puede causar tiempo de inactividad temporal.',

    // Confirmaciones de Base de Datos
    'confirm_migrate_database' => '¿Seguro que quieres ejecutar las migraciones de la base de datos? Esto puede modificar la estructura de tu base de datos.',
    'confirm_seed_database' => '¿Seguro que quieres poblar la base de datos? Esto añadirá datos de ejemplo.',
    'confirm_reset_database' => '¿Seguro que quieres resetear la base de datos? Esto eliminará todos los datos.',
    'confirm_backup_database' => '¿Seguro que quieres hacer una copia de seguridad de la base de datos? Esto puede tardar varios minutos.',
    'confirm_restore_database' => '¿Seguro que quieres restaurar la base de datos? Esto sobrescribirá los datos actuales.',
    'confirm_optimize_database' => '¿Seguro que quieres optimizar la base de datos? Esto puede tardar varios minutos.',
    'confirm_clean_database' => '¿Seguro que quieres limpiar la base de datos? Esto eliminará registros antiguos.',

    // Confirmaciones de Gestión de Usuarios
    'confirm_create_user' => '¿Seguro que quieres crear este usuario?',
    'confirm_update_user' => '¿Seguro que quieres actualizar este usuario?',
    'confirm_delete_user' => '¿Seguro que quieres eliminar este usuario? Esta acción no se puede deshacer.',
    'confirm_activate_user' => '¿Seguro que quieres activar este usuario?',
    'confirm_deactivate_user' => '¿Seguro que quieres desactivar este usuario?',
    'confirm_suspend_user' => '¿Seguro que quieres suspender este usuario?',
    'confirm_unsuspend_user' => '¿Seguro que quieres rehabilitar este usuario?',
    'confirm_reset_user_password' => '¿Seguro que quieres resetear la contraseña de este usuario?',
    'confirm_send_reset_email' => '¿Seguro que quieres enviar un email de restablecimiento de contraseña a este usuario?',
    'confirm_change_user_role' => '¿Seguro que quieres cambiar el rol de este usuario?',
    'confirm_update_user_permissions' => '¿Seguro que quieres actualizar los permisos de este usuario?',

    // Confirmaciones de Roles y Permisos
    'confirm_create_role' => '¿Seguro que quieres crear este rol?',
    'confirm_update_role' => '¿Seguro que quieres actualizar este rol?',
    'confirm_delete_role' => '¿Seguro que quieres eliminar este rol? Los usuarios con este rol perderán acceso.',
    'confirm_create_permission' => '¿Seguro que quieres crear este permiso?',
    'confirm_update_permission' => '¿Seguro que quieres actualizar este permiso?',
    'confirm_delete_permission' => '¿Seguro que quieres eliminar este permiso?',
    'confirm_assign_role' => '¿Seguro que quieres asignar este rol?',
    'confirm_remove_role' => '¿Seguro que quieres eliminar este rol?',
    'confirm_update_role_permissions' => '¿Seguro que quieres actualizar los permisos de este rol?',

    // Confirmaciones de API
    'confirm_create_api_key' => '¿Seguro que quieres crear una nueva clave de API?',
    'confirm_update_api_key' => '¿Seguro que quieres actualizar esta clave de API?',
    'confirm_delete_api_key' => '¿Seguro que quieres eliminar esta clave de API?',
    'confirm_revoke_api_key' => '¿Seguro que quieres revocar esta clave de API? Esta acción no se puede deshacer.',
    'confirm_regenerate_api_key' => '¿Seguro que quieres regenerar esta clave de API? La clave antigua será invalidada.',
    'confirm_create_api_endpoint' => '¿Seguro que quieres crear este endpoint de API?',
    'confirm_update_api_endpoint' => '¿Seguro que quieres actualizar este endpoint de API?',
    'confirm_delete_api_endpoint' => '¿Seguro que quieres eliminar este endpoint de API?',

    // Confirmaciones de Notificaciones y Emails
    'confirm_send_email' => '¿Seguro que quieres enviar este email?',
    'confirm_send_notification' => '¿Seguro que quieres enviar esta notificación?',
    'confirm_send_bulk_email' => '¿Seguro que quieres enviar emails a :count destinatarios?',
    'confirm_send_bulk_notification' => '¿Seguro que quieres enviar notificaciones a :count usuarios?',
    'confirm_save_email_template' => '¿Seguro que quieres guardar esta plantilla de email?',
    'confirm_save_notification_template' => '¿Seguro que quieres guardar esta plantilla de notificación?',
    'confirm_delete_email_template' => '¿Seguro que quieres eliminar esta plantilla de email?',
    'confirm_delete_notification_template' => '¿Seguro que quieres eliminar esta plantilla de notificación?',

    // Confirmaciones de Formularios y Datos
    'confirm_leave_form' => 'Tienes cambios sin guardar. ¿Seguro que quieres salir?',
    'confirm_discard_changes' => '¿Seguro que quieres descartar tus cambios?',
    'confirm_reset_form' => '¿Seguro que quieres resetear este formulario? Todos los datos introducidos se perderán.',
    'confirm_clear_form' => '¿Seguro que quieres limpiar este formulario? Todos los datos introducidos se perderán.',
    'confirm_submit_form' => '¿Seguro que quieres enviar este formulario?',
    'confirm_save_draft' => '¿Seguro que quieres guardar esto como borrador?',
    'confirm_publish' => '¿Seguro que quieres publicar este elemento?',
    'confirm_unpublish' => '¿Seguro que quieres despublicar este elemento?',
    'confirm_archive' => '¿Seguro que quieres archivar este elemento?',
    'confirm_unarchive' => '¿Seguro que quieres desarchivar este elemento?',

    // Confirmaciones de Pagos y Facturación
    'confirm_process_payment' => '¿Seguro que quieres procesar este pago?',
    'confirm_refund_payment' => '¿Seguro que quieres procesar este reembolso?',
    'confirm_cancel_subscription' => '¿Seguro que quieres cancelar esta suscripción?',
    'confirm_renew_subscription' => '¿Seguro que quieres renovar esta suscripción?',
    'confirm_change_plan' => '¿Seguro que quieres cambiar a este plan?',
    'confirm_delete_payment_method' => '¿Seguro que quieres eliminar este método de pago?',
    'confirm_update_billing_info' => '¿Seguro que quieres actualizar tu información de facturación?',

    // Operaciones Avanzadas y Peligrosas
    'confirm_dangerous_action' => 'Esta acción es potencialmente peligrosa. ¿Seguro, segurísimo de que quieres proceder?',
    'confirm_irreversible_action' => 'Esta acción no se puede deshacer. ¿Seguro, segurísimo de que quieres proceder?',
    'confirm_system_restart' => 'Esto reiniciará todo el sistema. ¿Estás absolutamente seguro?',
    'confirm_data_loss' => 'Esta acción puede resultar en pérdida de datos. ¿Estás absolutamente seguro?',
    'confirm_override_settings' => 'Esto sobrescribirá tu configuración actual. ¿Estás seguro?',
    'confirm_force_update' => 'Esto forzará una actualización incluso si hay conflictos. ¿Estás seguro?',
    'confirm_emergency_mode' => 'Esto habilitará el modo de emergencia. ¿Estás absolutamente seguro?',

    // Confirmaciones Genéricas
    'confirm_yes' => 'Sí, estoy seguro',
    'confirm_no' => 'No, cancelar',
    'confirm_ok' => 'Aceptar',
    'confirm_cancel' => 'Cancelar',
    'confirm_close' => 'Cerrar',
    'confirm_back' => 'Volver atrás',
    'confirm_proceed_anyway' => 'Proceder de todas formas',
    'confirm_understand' => 'Entiendo las consecuencias',
    'confirm_acknowledge' => 'Lo reconozco',
    'confirm_accept' => 'Acepto',
    'confirm_decline' => 'Rechazo',
    'confirm_continue_anyway' => 'Continuar de todas formas',
    'confirm_skip' => 'Omitir',
    'confirm_retry' => 'Reintentar',
    'confirm_abort' => 'Abortar',
    'confirm_force' => 'Forzar',
    'confirm_ignore' => 'Ignorar',
    'confirm_override' => 'Sobrescribir',
    'confirm_replace' => 'Reemplazar',
    'confirm_merge' => 'Combinar',
    'confirm_split' => 'Dividir',
    'confirm_duplicate' => 'Duplicar',
    'confirm_clone' => 'Clonar',
    'confirm_copy' => 'Copiar',
    'confirm_move' => 'Mover',
    'confirm_rename' => 'Renombrar',
    'confirm_restore' => 'Restaurar',
    'confirm_archive' => 'Archivar',
    'confirm_unarchive' => 'Desarchivar',
    'confirm_publish' => 'Publicar',
    'confirm_unpublish' => 'Despublicar',
    'confirm_activate' => 'Activar',
    'confirm_deactivate' => 'Desactivar',
    'confirm_enable' => 'Habilitar',
    'confirm_disable' => 'Deshabilitar',
    'confirm_show' => 'Mostrar',
    'confirm_hide' => 'Ocultar',
    'confirm_expand' => 'Expandir',
    'confirm_collapse' => 'Contraer',
    'confirm_minimize' => 'Minimizar',
    'confirm_maximize' => 'Maximizar',
    'confirm_fullscreen' => 'Entrar en pantalla completa',
    'confirm_exit_fullscreen' => 'Salir de pantalla completa',
];
