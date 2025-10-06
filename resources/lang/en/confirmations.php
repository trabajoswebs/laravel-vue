<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Confirmation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for confirmation messages that appear
    | throughout the application interface when users need to confirm actions.
    |
    */

    // General Confirmations
    'confirm_action' => 'Are you sure you’d like to carry out this action?',
    'confirm_continue' => 'Are you sure you’d like to continue?',
    'confirm_proceed' => 'Are you sure you’d like to proceed?',
    'confirm_submit' => 'Are you sure you’d like to submit this form?',
    'confirm_save' => 'Are you sure you’d like to save these changes?',
    'confirm_update' => 'Are you sure you’d like to update this item?',
    'confirm_create' => 'Are you sure you’d like to create this item?',

    // Delete Confirmations
    'confirm_delete' => 'Are you sure you’d like to delete this item?',
    'confirm_delete_selected' => 'Are you sure you’d like to delete the selected items?',
    'confirm_delete_all' => 'Are you sure you’d like to delete all items?',
    'confirm_delete_permanent' => 'Are you sure you’d like to permanently delete this item? This action cannot be undone.',
    'confirm_delete_multiple' => 'Are you sure you’d like to delete :count items?',
    'confirm_delete_user' => 'Are you sure you’d like to delete this user?',
    'confirm_delete_role' => 'Are you sure you’d like to delete this role?',
    'confirm_delete_permission' => 'Are you sure you’d like to delete this permission?',
    'confirm_delete_file' => 'Are you sure you’d like to delete this file?',
    'confirm_delete_files' => 'Are you sure you’d like to delete these files?',
    'confirm_delete_backup' => 'Are you sure you’d like to delete this backup?',
    'confirm_delete_log' => 'Are you sure you’d like to delete this log?',
    'confirm_delete_cache' => 'Are you sure you’d like to clear the cache?',
    'confirm_delete_sessions' => 'Are you sure you’d like to clear all sessions?',

    // Account and Profile Confirmations
    'confirm_logout' => 'Are you sure you’d like to sign out?',
    'confirm_change_password' => 'Are you sure you’d like to change your password?',
    'confirm_delete_account' => 'Are you sure you’d like to delete your account? This action cannot be undone.',
    'confirm_deactivate_account' => 'Are you sure you’d like to deactivate your account?',
    'confirm_reactivate_account' => 'Are you sure you’d like to reactivate your account?',
    'confirm_suspend_account' => 'Are you sure you’d like to suspend this account?',
    'confirm_unsuspend_account' => 'Are you sure you’d like to unsuspend this account?',
    'confirm_lock_account' => 'Are you sure you’d like to lock this account?',
    'confirm_unlock_account' => 'Are you sure you’d like to unlock this account?',
    'confirm_verify_email' => 'Are you sure you’d like to send a verification email?',

    // Security Confirmations
    'confirm_enable_2fa' => 'Are you sure you’d like to enable two-factor authentication?',
    'confirm_disable_2fa' => 'Are you sure you’d like to disable two-factor authentication?',
    'confirm_generate_backup_codes' => 'Are you sure you’d like to generate new backup codes? This will invalidate your old codes.',
    'confirm_revoke_api_key' => 'Are you sure you’d like to revoke this API key? This action cannot be undone.',
    'confirm_block_ip' => 'Are you sure you’d like to block this IP address?',
    'confirm_unblock_ip' => 'Are you sure you’d like to unblock this IP address?',
    'confirm_whitelist_ip' => 'Are you sure you’d like to whitelist this IP address?',
    'confirm_blacklist_ip' => 'Are you sure you’d like to blacklist this IP address?',
    'confirm_scan_security' => 'Are you sure you’d like to run a security scan? This may take several minutes.',

    // File and Upload Confirmations
    'confirm_upload_file' => 'Are you sure you’d like to upload this file?',
    'confirm_upload_files' => 'Are you sure you’d like to upload these files?',
    'confirm_replace_file' => 'Are you sure you’d like to replace this file? The old file will be deleted.',
    'confirm_move_file' => 'Are you sure you’d like to move this file?',
    'confirm_copy_file' => 'Are you sure you’d like to copy this file?',
    'confirm_rename_file' => 'Are you sure you’d like to rename this file?',
    'confirm_download_file' => 'Are you sure you’d like to download this file?',
    'confirm_export_data' => 'Are you sure you’d like to export this data?',
    'confirm_import_data' => 'Are you sure you’d like to import this data? This may overwrite existing data.',

    // System and Maintenance Confirmations
    'confirm_maintenance_mode' => 'Are you sure you’d like to switch on maintenance mode? Users will not be able to access the application.',
    'confirm_disable_maintenance' => 'Are you sure you’d like to switch off maintenance mode?',
    'confirm_restart_system' => 'Are you sure you’d like to restart the system? This will cause temporary downtime.',
    'confirm_shutdown_system' => 'Are you sure you’d like to shutdown the system? This will cause complete downtime.',
    'confirm_backup_system' => 'Are you sure you’d like to create a system backup? This may take several minutes.',
    'confirm_restore_system' => 'Are you sure you’d like to restore the system from backup? This will overwrite current data.',
    'confirm_optimize_system' => 'Are you sure you’d like to optimize the system? This may take several minutes.',
    'confirm_clean_system' => 'Are you sure you’d like to clean the system? This will remove temporary files.',
    'confirm_update_system' => 'Are you sure you’d like to update the system? This may cause temporary downtime.',

    // Database Confirmations
    'confirm_migrate_database' => 'Are you sure you’d like to run database migrations? This may modify your database structure.',
    'confirm_seed_database' => 'Are you sure you’d like to seed the database? This will add sample data.',
    'confirm_reset_database' => 'Are you sure you’d like to reset the database? This will delete all data.',
    'confirm_backup_database' => 'Are you sure you’d like to backup the database? This may take several minutes.',
    'confirm_restore_database' => 'Are you sure you’d like to restore the database? This will overwrite current data.',
    'confirm_optimize_database' => 'Are you sure you’d like to optimize the database? This may take several minutes.',
    'confirm_clean_database' => 'Are you sure you’d like to clean the database? This will remove old records.',

    // User Management Confirmations
    'confirm_create_user' => 'Are you sure you’d like to create this user?',
    'confirm_update_user' => 'Are you sure you’d like to update this user?',
    'confirm_delete_user' => 'Are you sure you’d like to delete this user? This action cannot be undone.',
    'confirm_activate_user' => 'Are you sure you’d like to activate this user?',
    'confirm_deactivate_user' => 'Are you sure you’d like to deactivate this user?',
    'confirm_suspend_user' => 'Are you sure you’d like to suspend this user?',
    'confirm_unsuspend_user' => 'Are you sure you’d like to unsuspend this user?',
    'confirm_reset_user_password' => 'Are you sure you’d like to reset this user\'s password?',
    'confirm_send_reset_email' => 'Are you sure you’d like to send a password reset email to this user?',
    'confirm_change_user_role' => 'Are you sure you’d like to change this user\'s role?',
    'confirm_update_user_permissions' => 'Are you sure you’d like to update this user\'s permissions?',

    // Role and Permission Confirmations
    'confirm_create_role' => 'Are you sure you’d like to create this role?',
    'confirm_update_role' => 'Are you sure you’d like to update this role?',
    'confirm_delete_role' => 'Are you sure you’d like to delete this role? Users with this role will lose access.',
    'confirm_create_permission' => 'Are you sure you’d like to create this permission?',
    'confirm_update_permission' => 'Are you sure you’d like to update this permission?',
    'confirm_delete_permission' => 'Are you sure you’d like to delete this permission?',
    'confirm_assign_role' => 'Are you sure you’d like to assign this role?',
    'confirm_remove_role' => 'Are you sure you’d like to remove this role?',
    'confirm_update_role_permissions' => 'Are you sure you’d like to update this role\'s permissions?',

    // API Confirmations
    'confirm_create_api_key' => 'Are you sure you’d like to create a new API key?',
    'confirm_update_api_key' => 'Are you sure you’d like to update this API key?',
    'confirm_delete_api_key' => 'Are you sure you’d like to delete this API key?',
    'confirm_revoke_api_key' => 'Are you sure you’d like to revoke this API key? This action cannot be undone.',
    'confirm_regenerate_api_key' => 'Are you sure you’d like to regenerate this API key? The old key will be invalidated.',
    'confirm_create_api_endpoint' => 'Are you sure you’d like to create this API endpoint?',
    'confirm_update_api_endpoint' => 'Are you sure you’d like to update this API endpoint?',
    'confirm_delete_api_endpoint' => 'Are you sure you’d like to delete this API endpoint?',

    // Notification and Email Confirmations
    'confirm_send_email' => 'Are you sure you’d like to send this email?',
    'confirm_send_notification' => 'Are you sure you’d like to send this notification?',
    'confirm_send_bulk_email' => 'Are you sure you’d like to send emails to :count recipients?',
    'confirm_send_bulk_notification' => 'Are you sure you’d like to send notifications to :count users?',
    'confirm_save_email_template' => 'Are you sure you’d like to save this email template?',
    'confirm_save_notification_template' => 'Are you sure you’d like to save this notification template?',
    'confirm_delete_email_template' => 'Are you sure you’d like to delete this email template?',
    'confirm_delete_notification_template' => 'Are you sure you’d like to delete this notification template?',

    // Form and Data Confirmations
    'confirm_leave_form' => 'You have unsaved changes. Are you sure you’d like to leave?',
    'confirm_discard_changes' => 'Are you sure you’d like to discard your changes?',
    'confirm_reset_form' => 'Are you sure you’d like to reset this form? All entered data will be lost.',
    'confirm_clear_form' => 'Are you sure you’d like to clear this form? All entered data will be lost.',
    'confirm_submit_form' => 'Are you sure you’d like to submit this form?',
    'confirm_save_draft' => 'Are you sure you’d like to save this as a draft?',
    'confirm_publish' => 'Are you sure you’d like to publish this item?',
    'confirm_unpublish' => 'Are you sure you’d like to unpublish this item?',
    'confirm_archive' => 'Are you sure you’d like to archive this item?',
    'confirm_unarchive' => 'Are you sure you’d like to unarchive this item?',

    // Payment and Billing Confirmations
    'confirm_process_payment' => 'Are you sure you’d like to process this payment?',
    'confirm_refund_payment' => 'Are you sure you’d like to process this refund?',
    'confirm_cancel_subscription' => 'Are you sure you’d like to cancel this subscription?',
    'confirm_renew_subscription' => 'Are you sure you’d like to renew this subscription?',
    'confirm_change_plan' => 'Are you sure you’d like to change to this plan?',
    'confirm_delete_payment_method' => 'Are you sure you’d like to delete this payment method?',
    'confirm_update_billing_info' => 'Are you sure you’d like to update your billing information?',

    // Advanced and Dangerous Operations
    'confirm_dangerous_action' => 'This action is potentially dangerous. Are you absolutely sure you want to proceed?',
    'confirm_irreversible_action' => 'This action cannot be undone. Are you absolutely sure you want to proceed?',
    'confirm_system_restart' => 'This will restart the entire system. Are you absolutely sure?',
    'confirm_data_loss' => 'This action may result in data loss. Are you absolutely sure?',
    'confirm_override_settings' => 'This will override your current settings. Are you sure?',
    'confirm_force_update' => 'This will force an update even if there are conflicts. Are you sure?',
    'confirm_emergency_mode' => 'This will enable emergency mode. Are you absolutely sure?',

    // Generic Confirmations
    'confirm_yes' => 'Yes, I am sure',
    'confirm_no' => 'No, cancel',
    'confirm_ok' => 'OK',
    'confirm_cancel' => 'Cancel',
    'confirm_close' => 'Close',
    'confirm_back' => 'Go back',
    'confirm_proceed_anyway' => 'Proceed anyway',
    'confirm_understand' => 'I understand the consequences',
    'confirm_acknowledge' => 'I acknowledge',
    'confirm_accept' => 'I accept',
    'confirm_decline' => 'I decline',
    'confirm_continue_anyway' => 'Continue anyway',
    'confirm_skip' => 'Skip',
    'confirm_retry' => 'Retry',
    'confirm_abort' => 'Abort',
    'confirm_force' => 'Force',
    'confirm_ignore' => 'Ignore',
    'confirm_override' => 'Override',
    'confirm_replace' => 'Replace',
    'confirm_merge' => 'Merge',
    'confirm_split' => 'Split',
    'confirm_duplicate' => 'Duplicate',
    'confirm_clone' => 'Clone',
    'confirm_copy' => 'Copy',
    'confirm_move' => 'Move',
    'confirm_rename' => 'Rename',
    'confirm_restore' => 'Restore',
    'confirm_archive' => 'Archive',
    'confirm_unarchive' => 'Unarchive',
    'confirm_publish' => 'Publish',
    'confirm_unpublish' => 'Unpublish',
    'confirm_activate' => 'Activate',
    'confirm_deactivate' => 'Deactivate',
    'confirm_enable' => 'Enable',
    'confirm_disable' => 'Disable',
    'confirm_show' => 'Show',
    'confirm_hide' => 'Hide',
    'confirm_expand' => 'Expand',
    'confirm_collapse' => 'Collapse',
    'confirm_minimize' => 'Minimize',
    'confirm_maximize' => 'Maximize',
    'confirm_fullscreen' => 'Enter fullscreen',
    'confirm_exit_fullscreen' => 'Exit fullscreen',
];
