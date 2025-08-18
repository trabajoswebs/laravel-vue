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
    'confirm_action' => '¿Estás seguro de que quieres realizar esta acción?',
    'confirm_continue' => '¿Estás seguro de que quieres continuar?',
    'confirm_proceed' => '¿Estás seguro de que quieres proceder?',
    'confirm_submit' => '¿Estás seguro de que quieres enviar este formulario?',
    'confirm_save' => '¿Estás seguro de que quieres guardar estos cambios?',
    'confirm_update' => '¿Estás seguro de que quieres actualizar este elemento?',
    'confirm_create' => '¿Estás seguro de que quieres crear este elemento?',

    // Confirmaciones de Eliminación
    'confirm_delete' => '¿Estás seguro de que quieres eliminar este elemento?',
    'confirm_delete_selected' => '¿Estás seguro de que quieres eliminar los elementos seleccionados?',
    'confirm_delete_all' => '¿Estás seguro de que quieres eliminar todos los elementos?',
    'confirm_delete_permanent' => '¿Estás seguro de que quieres eliminar permanentemente este elemento? Esta acción no se puede deshacer.',
    'confirm_delete_multiple' => '¿Estás seguro de que quieres eliminar :count elementos?',
    'confirm_delete_user' => '¿Estás seguro de que quieres eliminar este usuario?',
    'confirm_delete_role' => '¿Estás seguro de que quieres eliminar este rol?',
    'confirm_delete_permission' => '¿Estás seguro de que quieres eliminar este permiso?',
    'confirm_delete_file' => '¿Estás seguro de que quieres eliminar este archivo?',
    'confirm_delete_files' => '¿Estás seguro de que quieres eliminar estos archivos?',
    'confirm_delete_backup' => '¿Estás seguro de que quieres eliminar esta copia de seguridad?',
    'confirm_delete_log' => '¿Estás seguro de que quieres eliminar este log?',
    'confirm_delete_cache' => '¿Estás seguro de que quieres limpiar la caché?',
    'confirm_delete_sessions' => '¿Estás seguro de que quieres limpiar todas las sesiones?',

    // Confirmaciones de Cuenta y Perfil
    'confirm_logout' => '¿Estás seguro de que quieres cerrar sesión?',
    'confirm_change_password' => '¿Estás seguro de que quieres cambiar tu contraseña?',
    'confirm_delete_account' => '¿Estás seguro de que quieres eliminar tu cuenta? Esta acción no se puede deshacer.',
    'confirm_deactivate_account' => '¿Estás seguro de que quieres desactivar tu cuenta?',
    'confirm_reactivate_account' => '¿Estás seguro de que quieres reactivar tu cuenta?',
    'confirm_suspend_account' => '¿Estás seguro de que quieres suspender esta cuenta?',
    'confirm_unsuspend_account' => '¿Estás seguro de que quieres rehabilitar esta cuenta?',
    'confirm_lock_account' => '¿Estás seguro de que quieres bloquear esta cuenta?',
    'confirm_unlock_account' => '¿Estás seguro de que quieres desbloquear esta cuenta?',
    'confirm_verify_email' => '¿Estás seguro de que quieres enviar un email de verificación?',

    // Confirmaciones de Seguridad
    'confirm_enable_2fa' => '¿Estás seguro de que quieres habilitar la autenticación de dos factores?',
    'confirm_disable_2fa' => '¿Estás seguro de que quieres deshabilitar la autenticación de dos factores?',
    'confirm_generate_backup_codes' => '¿Estás seguro de que quieres generar nuevos códigos de respaldo? Esto invalidará tus códigos antiguos.',
    'confirm_revoke_api_key' => '¿Estás seguro de que quieres revocar esta clave de API? Esta acción no se puede deshacer.',
    'confirm_block_ip' => '¿Estás seguro de que quieres bloquear esta dirección IP?',
    'confirm_unblock_ip' => '¿Estás seguro de que quieres desbloquear esta dirección IP?',
    'confirm_whitelist_ip' => '¿Estás seguro de que quieres añadir esta dirección IP a la lista blanca?',
    'confirm_blacklist_ip' => '¿Estás seguro de que quieres añadir esta dirección IP a la lista negra?',
    'confirm_scan_security' => '¿Estás seguro de que quieres ejecutar un escaneo de seguridad? Esto puede tardar varios minutos.',

    // Confirmaciones de Archivos y Subidas
    'confirm_upload_file' => '¿Estás seguro de que quieres subir este archivo?',
    'confirm_upload_files' => '¿Estás seguro de que quieres subir estos archivos?',
    'confirm_replace_file' => '¿Estás seguro de que quieres reemplazar este archivo? El archivo antiguo será eliminado.',
    'confirm_move_file' => '¿Estás seguro de que quieres mover este archivo?',
    'confirm_copy_file' => '¿Estás seguro de que quieres copiar este archivo?',
    'confirm_rename_file' => '¿Estás seguro de que quieres renombrar este archivo?',
    'confirm_download_file' => '¿Estás seguro de que quieres descargar este archivo?',
    'confirm_export_data' => '¿Estás seguro de que quieres exportar estos datos?',
    'confirm_import_data' => '¿Estás seguro de que quieres importar estos datos? Esto puede sobrescribir datos existentes.',

    // Confirmaciones de Sistema y Mantenimiento
    'confirm_maintenance_mode' => '¿Estás seguro de que quieres habilitar el modo de mantenimiento? Los usuarios no podrán acceder a la aplicación.',
    'confirm_disable_maintenance' => '¿Estás seguro de que quieres deshabilitar el modo de mantenimiento?',
    'confirm_restart_system' => '¿Estás seguro de que quieres reiniciar el sistema? Esto causará tiempo de inactividad temporal.',
    'confirm_shutdown_system' => '¿Estás seguro de que quieres apagar el sistema? Esto causará tiempo de inactividad completo.',
    'confirm_backup_system' => '¿Estás seguro de que quieres crear una copia de seguridad del sistema? Esto puede tardar varios minutos.',
    'confirm_restore_system' => '¿Estás seguro de que quieres restaurar el sistema desde la copia de seguridad? Esto sobrescribirá los datos actuales.',
    'confirm_optimize_system' => '¿Estás seguro de que quieres optimizar el sistema? Esto puede tardar varios minutos.',
    'confirm_clean_system' => '¿Estás seguro de que quieres limpiar el sistema? Esto eliminará archivos temporales.',
    'confirm_update_system' => '¿Estás seguro de que quieres actualizar el sistema? Esto puede causar tiempo de inactividad temporal.',

    // Confirmaciones de Base de Datos
    'confirm_migrate_database' => '¿Estás seguro de que quieres ejecutar las migraciones de la base de datos? Esto puede modificar la estructura de tu base de datos.',
    'confirm_seed_database' => '¿Estás seguro de que quieres poblar la base de datos? Esto añadirá datos de ejemplo.',
    'confirm_reset_database' => '¿Estás seguro de que quieres resetear la base de datos? Esto eliminará todos los datos.',
    'confirm_backup_database' => '¿Estás seguro de que quieres hacer una copia de seguridad de la base de datos? Esto puede tardar varios minutos.',
    'confirm_restore_database' => '¿Estás seguro de que quieres restaurar la base de datos? Esto sobrescribirá los datos actuales.',
    'confirm_optimize_database' => '¿Estás seguro de que quieres optimizar la base de datos? Esto puede tardar varios minutos.',
    'confirm_clean_database' => '¿Estás seguro de que quieres limpiar la base de datos? Esto eliminará registros antiguos.',

    // Confirmaciones de Gestión de Usuarios
    'confirm_create_user' => '¿Estás seguro de que quieres crear este usuario?',
    'confirm_update_user' => '¿Estás seguro de que quieres actualizar este usuario?',
    'confirm_delete_user' => '¿Estás seguro de que quieres eliminar este usuario? Esta acción no se puede deshacer.',
    'confirm_activate_user' => '¿Estás seguro de que quieres activar este usuario?',
    'confirm_deactivate_user' => '¿Estás seguro de que quieres desactivar este usuario?',
    'confirm_suspend_user' => '¿Estás seguro de que quieres suspender este usuario?',
    'confirm_unsuspend_user' => '¿Estás seguro de que quieres rehabilitar este usuario?',
    'confirm_reset_user_password' => '¿Estás seguro de que quieres resetear la contraseña de este usuario?',
    'confirm_send_reset_email' => '¿Estás seguro de que quieres enviar un email de restablecimiento de contraseña a este usuario?',
    'confirm_change_user_role' => '¿Estás seguro de que quieres cambiar el rol de este usuario?',
    'confirm_update_user_permissions' => '¿Estás seguro de que quieres actualizar los permisos de este usuario?',

    // Confirmaciones de Roles y Permisos
    'confirm_create_role' => '¿Estás seguro de que quieres crear este rol?',
    'confirm_update_role' => '¿Estás seguro de que quieres actualizar este rol?',
    'confirm_delete_role' => '¿Estás seguro de que quieres eliminar este rol? Los usuarios con este rol perderán acceso.',
    'confirm_create_permission' => '¿Estás seguro de que quieres crear este permiso?',
    'confirm_update_permission' => '¿Estás seguro de que quieres actualizar este permiso?',
    'confirm_delete_permission' => '¿Estás seguro de que quieres eliminar este permiso?',
    'confirm_assign_role' => '¿Estás seguro de que quieres asignar este rol?',
    'confirm_remove_role' => '¿Estás seguro de que quieres eliminar este rol?',
    'confirm_update_role_permissions' => '¿Estás seguro de que quieres actualizar los permisos de este rol?',

    // Confirmaciones de API
    'confirm_create_api_key' => '¿Estás seguro de que quieres crear una nueva clave de API?',
    'confirm_update_api_key' => '¿Estás seguro de que quieres actualizar esta clave de API?',
    'confirm_delete_api_key' => '¿Estás seguro de que quieres eliminar esta clave de API?',
    'confirm_revoke_api_key' => '¿Estás seguro de que quieres revocar esta clave de API? Esta acción no se puede deshacer.',
    'confirm_regenerate_api_key' => '¿Estás seguro de que quieres regenerar esta clave de API? La clave antigua será invalidada.',
    'confirm_create_api_endpoint' => '¿Estás seguro de que quieres crear este endpoint de API?',
    'confirm_update_api_endpoint' => '¿Estás seguro de que quieres actualizar este endpoint de API?',
    'confirm_delete_api_endpoint' => '¿Estás seguro de que quieres eliminar este endpoint de API?',

    // Confirmaciones de Notificaciones y Emails
    'confirm_send_email' => '¿Estás seguro de que quieres enviar este email?',
    'confirm_send_notification' => '¿Estás seguro de que quieres enviar esta notificación?',
    'confirm_send_bulk_email' => '¿Estás seguro de que quieres enviar emails a :count destinatarios?',
    'confirm_send_bulk_notification' => '¿Estás seguro de que quieres enviar notificaciones a :count usuarios?',
    'confirm_save_email_template' => '¿Estás seguro de que quieres guardar esta plantilla de email?',
    'confirm_save_notification_template' => '¿Estás seguro de que quieres guardar esta plantilla de notificación?',
    'confirm_delete_email_template' => '¿Estás seguro de que quieres eliminar esta plantilla de email?',
    'confirm_delete_notification_template' => '¿Estás seguro de que quieres eliminar esta plantilla de notificación?',

    // Confirmaciones de Formularios y Datos
    'confirm_leave_form' => 'Tienes cambios sin guardar. ¿Estás seguro de que quieres salir?',
    'confirm_discard_changes' => '¿Estás seguro de que quieres descartar tus cambios?',
    'confirm_reset_form' => '¿Estás seguro de que quieres resetear este formulario? Todos los datos introducidos se perderán.',
    'confirm_clear_form' => '¿Estás seguro de que quieres limpiar este formulario? Todos los datos introducidos se perderán.',
    'confirm_submit_form' => '¿Estás seguro de que quieres enviar este formulario?',
    'confirm_save_draft' => '¿Estás seguro de que quieres guardar esto como borrador?',
    'confirm_publish' => '¿Estás seguro de que quieres publicar este elemento?',
    'confirm_unpublish' => '¿Estás seguro de que quieres despublicar este elemento?',
    'confirm_archive' => '¿Estás seguro de que quieres archivar este elemento?',
    'confirm_unarchive' => '¿Estás seguro de que quieres desarchivar este elemento?',

    // Confirmaciones de Pagos y Facturación
    'confirm_process_payment' => '¿Estás seguro de que quieres procesar este pago?',
    'confirm_refund_payment' => '¿Estás seguro de que quieres procesar este reembolso?',
    'confirm_cancel_subscription' => '¿Estás seguro de que quieres cancelar esta suscripción?',
    'confirm_renew_subscription' => '¿Estás seguro de que quieres renovar esta suscripción?',
    'confirm_change_plan' => '¿Estás seguro de que quieres cambiar a este plan?',
    'confirm_delete_payment_method' => '¿Estás seguro de que quieres eliminar este método de pago?',
    'confirm_update_billing_info' => '¿Estás seguro de que quieres actualizar tu información de facturación?',

    // Operaciones Avanzadas y Peligrosas
    'confirm_dangerous_action' => 'Esta acción es potencialmente peligrosa. ¿Estás absolutamente seguro de que quieres proceder?',
    'confirm_irreversible_action' => 'Esta acción no se puede deshacer. ¿Estás absolutamente seguro de que quieres proceder?',
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
