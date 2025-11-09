<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Líneas de Idioma para Confirmaciones
    |--------------------------------------------------------------------------
    |
    | Confirmaciones claras y directas para acciones importantes
    |
    */

    // Confirmaciones Generales
    'confirm_action' => '¿Quieres hacer esto?',
    'confirm_continue' => '¿Continuar?',
    'confirm_proceed' => '¿Seguir adelante?',
    'confirm_submit' => '¿Enviar el formulario?',
    'confirm_save' => '¿Guardar los cambios?',
    'confirm_update' => '¿Actualizar?',
    'confirm_create' => '¿Crear?',

    // Confirmaciones de Eliminación
    'confirm_delete' => '¿Eliminar?',
    'confirm_delete_selected' => '¿Eliminar lo seleccionado?',
    'confirm_delete_all' => '¿Eliminar todo?',
    'confirm_delete_permanent' => '¿Eliminar para siempre? No podrás recuperarlo.',
    'confirm_delete_multiple' => '¿Eliminar :count elementos?',
    'confirm_delete_user' => '¿Eliminar este usuario?',
    'confirm_delete_role' => '¿Eliminar este rol?',
    'confirm_delete_permission' => '¿Eliminar este permiso?',
    'confirm_delete_file' => '¿Eliminar este archivo?',
    'confirm_delete_files' => '¿Eliminar estos archivos?',
    'confirm_delete_backup' => '¿Eliminar esta copia de seguridad?',
    'confirm_delete_log' => '¿Eliminar este registro?',
    'confirm_delete_cache' => '¿Limpiar la caché?',
    'confirm_delete_sessions' => '¿Limpiar todas las sesiones?',

    // Confirmaciones de Cuenta y Perfil
    'confirm_logout' => '¿Salir de tu cuenta?',
    'confirm_change_password' => '¿Cambiar tu contraseña?',
    'confirm_delete_account' => '¿Eliminar tu cuenta? No podrás volver atrás.',
    'confirm_deactivate_account' => '¿Desactivar tu cuenta?',
    'confirm_reactivate_account' => '¿Reactivar tu cuenta?',
    'confirm_suspend_account' => '¿Suspender esta cuenta?',
    'confirm_unsuspend_account' => '¿Reactivar esta cuenta?',
    'confirm_lock_account' => '¿Bloquear esta cuenta?',
    'confirm_unlock_account' => '¿Desbloquear esta cuenta?',
    'confirm_verify_email' => '¿Enviar email de verificación?',

    // Confirmaciones de Seguridad
    'confirm_enable_2fa' => '¿Activar la verificación en dos pasos?',
    'confirm_disable_2fa' => '¿Desactivar la verificación en dos pasos?',
    'confirm_generate_backup_codes' => '¿Generar nuevos códigos de respaldo? Los antiguos dejarán de funcionar.',
    'confirm_revoke_api_key' => '¿Revocar esta clave de API? No podrás deshacerlo.',
    'confirm_block_ip' => '¿Bloquear esta IP?',
    'confirm_unblock_ip' => '¿Desbloquear esta IP?',
    'confirm_whitelist_ip' => '¿Añadir esta IP a la lista permitida?',
    'confirm_blacklist_ip' => '¿Añadir esta IP a la lista bloqueada?',
    'confirm_scan_security' => '¿Hacer un análisis de seguridad? Puede tardar unos minutos.',

    // Confirmaciones de Archivos y Subidas
    'confirm_upload_file' => '¿Subir este archivo?',
    'confirm_upload_files' => '¿Subir estos archivos?',
    'confirm_replace_file' => '¿Reemplazar este archivo? Se borrará el antiguo.',
    'confirm_move_file' => '¿Mover este archivo?',
    'confirm_copy_file' => '¿Copiar este archivo?',
    'confirm_rename_file' => '¿Cambiar el nombre?',
    'confirm_download_file' => '¿Descargar este archivo?',
    'confirm_export_data' => '¿Exportar estos datos?',
    'confirm_import_data' => '¿Importar estos datos? Puede borrar datos existentes.',

    // Confirmaciones de Sistema y Mantenimiento
    'confirm_maintenance_mode' => '¿Activar el modo mantenimiento? Los usuarios no podrán entrar.',
    'confirm_disable_maintenance' => '¿Desactivar el modo mantenimiento?',
    'confirm_restart_system' => '¿Reiniciar el sistema? Habrá una parada temporal.',
    'confirm_shutdown_system' => '¿Apagar el sistema? No estará disponible.',
    'confirm_backup_system' => '¿Hacer copia del sistema? Puede tardar unos minutos.',
    'confirm_restore_system' => '¿Restaurar el sistema? Se perderán los datos actuales.',
    'confirm_optimize_system' => '¿Optimizar el sistema? Puede tardar unos minutos.',
    'confirm_clean_system' => '¿Limpiar el sistema? Se borrarán archivos temporales.',
    'confirm_update_system' => '¿Actualizar el sistema? Habrá una parada temporal.',

    // Confirmaciones de Base de Datos
    'confirm_migrate_database' => '¿Actualizar la base de datos? Puede cambiar su estructura.',
    'confirm_seed_database' => '¿Añadir datos de ejemplo?',
    'confirm_reset_database' => '¿Borrar toda la base de datos? Se perderán todos los datos.',
    'confirm_backup_database' => '¿Hacer copia de la base de datos? Puede tardar unos minutos.',
    'confirm_restore_database' => '¿Restaurar la base de datos? Se perderán los datos actuales.',
    'confirm_optimize_database' => '¿Optimizar la base de datos? Puede tardar unos minutos.',
    'confirm_clean_database' => '¿Limpiar la base de datos? Se borrarán registros antiguos.',

    // Confirmaciones de Gestión de Usuarios
    'confirm_create_user' => '¿Crear este usuario?',
    'confirm_update_user' => '¿Actualizar este usuario?',
    'confirm_delete_user' => '¿Eliminar este usuario? No podrás recuperarlo.',
    'confirm_activate_user' => '¿Activar este usuario?',
    'confirm_deactivate_user' => '¿Desactivar este usuario?',
    'confirm_suspend_user' => '¿Suspender este usuario?',
    'confirm_unsuspend_user' => '¿Reactivar este usuario?',
    'confirm_reset_user_password' => '¿Restablecer la contraseña de este usuario?',
    'confirm_send_reset_email' => '¿Enviar email para cambiar contraseña?',
    'confirm_change_user_role' => '¿Cambiar el rol de este usuario?',
    'confirm_update_user_permissions' => '¿Actualizar los permisos de este usuario?',

    // Confirmaciones de Roles y Permisos
    'confirm_create_role' => '¿Crear este rol?',
    'confirm_update_role' => '¿Actualizar este rol?',
    'confirm_delete_role' => '¿Eliminar este rol? Los usuarios perderán acceso.',
    'confirm_create_permission' => '¿Crear este permiso?',
    'confirm_update_permission' => '¿Actualizar este permiso?',
    'confirm_delete_permission' => '¿Eliminar este permiso?',
    'confirm_assign_role' => '¿Asignar este rol?',
    'confirm_remove_role' => '¿Quitar este rol?',
    'confirm_update_role_permissions' => '¿Actualizar los permisos de este rol?',

    // Confirmaciones de API
    'confirm_create_api_key' => '¿Crear nueva clave de API?',
    'confirm_update_api_key' => '¿Actualizar esta clave de API?',
    'confirm_delete_api_key' => '¿Eliminar esta clave de API?',
    'confirm_revoke_api_key' => '¿Revocar esta clave de API? No podrás deshacerlo.',
    'confirm_regenerate_api_key' => '¿Regenerar esta clave? La antigua dejará de funcionar.',
    'confirm_create_api_endpoint' => '¿Crear este endpoint de API?',
    'confirm_update_api_endpoint' => '¿Actualizar este endpoint de API?',
    'confirm_delete_api_endpoint' => '¿Eliminar este endpoint de API?',

    // Confirmaciones de Notificaciones y Emails
    'confirm_send_email' => '¿Enviar este correo?',
    'confirm_send_notification' => '¿Enviar esta notificación?',
    'confirm_send_bulk_email' => '¿Enviar correos a :count personas?',
    'confirm_send_bulk_notification' => '¿Enviar notificaciones a :count usuarios?',
    'confirm_save_email_template' => '¿Guardar esta plantilla de correo?',
    'confirm_save_notification_template' => '¿Guardar esta plantilla de notificación?',
    'confirm_delete_email_template' => '¿Eliminar esta plantilla de correo?',
    'confirm_delete_notification_template' => '¿Eliminar esta plantilla de notificación?',

    // Confirmaciones de Formularios y Datos
    'confirm_leave_form' => 'Tienes cambios sin guardar. ¿Salir?',
    'confirm_discard_changes' => '¿Descartar los cambios?',
    'confirm_reset_form' => '¿Restablecer el formulario? Se perderán los datos.',
    'confirm_clear_form' => '¿Limpiar el formulario? Se perderán los datos.',
    'confirm_submit_form' => '¿Enviar el formulario?',
    'confirm_save_draft' => '¿Guardar como borrador?',
    'confirm_publish' => '¿Publicar?',
    'confirm_unpublish' => '¿Ocultar?',
    'confirm_archive' => '¿Archivar?',
    'confirm_unarchive' => '¿Desarchivar?',

    // Confirmaciones de Pagos y Facturación
    'confirm_process_payment' => '¿Procesar este pago?',
    'confirm_refund_payment' => '¿Hacer este reembolso?',
    'confirm_cancel_subscription' => '¿Cancelar esta suscripción?',
    'confirm_renew_subscription' => '¿Renovar esta suscripción?',
    'confirm_change_plan' => '¿Cambiar a este plan?',
    'confirm_delete_payment_method' => '¿Eliminar este método de pago?',
    'confirm_update_billing_info' => '¿Actualizar tus datos de facturación?',

    // Operaciones Avanzadas y Peligrosas
    'confirm_dangerous_action' => 'Esto es peligroso. ¿Estás completamente seguro?',
    'confirm_irreversible_action' => 'Esto no se puede deshacer. ¿Estás completamente seguro?',
    'confirm_system_restart' => 'Esto reiniciará todo el sistema. ¿Seguro?',
    'confirm_data_loss' => 'Puedes perder datos. ¿Seguro?',
    'confirm_override_settings' => 'Esto cambiará tu configuración. ¿Seguro?',
    'confirm_force_update' => 'Esto forzará la actualización. ¿Seguro?',
    'confirm_emergency_mode' => 'Esto activará el modo emergencia. ¿Seguro?',

    // Confirmaciones Genéricas
    'confirm_yes' => 'Sí, seguro',
    'confirm_no' => 'No, cancelar',
    'confirm_ok' => 'Aceptar',
    'confirm_cancel' => 'Cancelar',
    'confirm_close' => 'Cerrar',
    'confirm_back' => 'Volver',
    'confirm_proceed_anyway' => 'Continuar igualmente',
    'confirm_understand' => 'Entiendo',
    'confirm_acknowledge' => 'De acuerdo',
    'confirm_accept' => 'Aceptar',
    'confirm_decline' => 'Rechazar',
    'confirm_continue_anyway' => 'Continuar igualmente',
    'confirm_skip' => 'Saltar',
    'confirm_retry' => 'Reintentar',
    'confirm_abort' => 'Cancelar',
    'confirm_force' => 'Forzar',
    'confirm_ignore' => 'Ignorar',
    'confirm_override' => 'Cambiar',
    'confirm_replace' => 'Reemplazar',
    'confirm_merge' => 'Unir',
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
    'confirm_unpublish' => 'Ocultar',
    'confirm_activate' => 'Activar',
    'confirm_deactivate' => 'Desactivar',
    'confirm_enable' => 'Activar',
    'confirm_disable' => 'Desactivar',
    'confirm_show' => 'Mostrar',
    'confirm_hide' => 'Ocultar',
    'confirm_expand' => 'Abrir',
    'confirm_collapse' => 'Cerrar',
    'confirm_minimize' => 'Minimizar',
    'confirm_maximize' => 'Maximizar',
    'confirm_fullscreen' => 'Pantalla completa',
    'confirm_exit_fullscreen' => 'Salir de pantalla completa',
];
