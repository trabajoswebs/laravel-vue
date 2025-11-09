<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Error Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used for error messages that appear
    | throughout the application interface.
    |
    */

    // HTTP Error Codes
    '400' => [
        'title' => 'Bad Request',
        'message' => 'The request could not be understood by the server.',
        'description' => 'The server cannot process your request due to invalid syntax.',
    ],
    '401' => [
        'title' => 'Unauthorized',
        'message' => 'You are not authorized to access this resource.',
        'description' => 'Please log in with valid credentials to continue.',
    ],
    '403' => [
        'title' => 'Forbidden',
        'message' => 'Access to this resource is forbidden.',
        'description' => 'You do not have permission to access this page or resource.',
    ],
    '404' => [
        'title' => 'Page Not Found',
        'message' => 'The page you are looking for could not be found.',
        'description' => 'The page may have been moved, deleted, or you entered the wrong URL.',
    ],
    '405' => [
        'title' => 'Method Not Allowed',
        'message' => 'The HTTP method used is not allowed for this resource.',
        'description' => 'Please use a different HTTP method to access this resource.',
    ],
    '408' => [
        'title' => 'Request Timeout',
        'message' => 'The request timed out while waiting for a response.',
        'description' => 'The server took too long to respond. Please try again.',
    ],
    '422' => [
        'title' => 'Unprocessable Entity',
        'message' => 'The request was well-formed but contains invalid data.',
        'description' => 'Please check your input and try again.',
    ],
    '429' => [
        'title' => 'Too Many Requests',
        'message' => 'You have made too many requests in a short time.',
        'description' => 'Please wait a moment before trying again.',
    ],
    '500' => [
        'title' => 'Internal Server Error',
        'message' => 'Something went wrong on our end.',
        'description' => 'We are experiencing technical difficulties. Please try again later.',
    ],
    '502' => [
        'title' => 'Bad Gateway',
        'message' => 'The server received an invalid response from an upstream server.',
        'description' => 'We are experiencing connectivity issues. Please try again later.',
    ],
    '503' => [
        'title' => 'Service Unavailable',
        'message' => 'The service is temporarily unavailable.',
        'description' => 'We are performing maintenance. Please check back later.',
    ],
    '504' => [
        'title' => 'Gateway Timeout',
        'message' => 'The server did not receive a timely response.',
        'description' => 'The request took too long to process. Please try again.',
    ],

    // Application Errors
    'general_error' => 'An error occurred',
    'unexpected_error' => 'An unexpected error occurred',
    'system_error' => 'A system error occurred',
    'database_error' => 'A database error occurred',
    'connection_error' => 'A connection error occurred',
    'authentication_error' => 'An authentication error occurred',
    'authorization_error' => 'An authorization error occurred',
    'validation_error' => 'A validation error occurred',
    'file_error' => 'A file error occurred',
    'upload_error' => 'An upload error occurred',
    'download_error' => 'A download error occurred',
    'email_error' => 'An email error occurred',
    'notification_error' => 'A notification error occurred',

    // User-Friendly Error Messages
    'something_went_wrong' => 'Something went wrong',
    'try_again_later' => 'Please try again later',
    'contact_support' => 'If the problem persists, please contact support',
    'check_your_input' => 'Please check your input and try again',
    'refresh_page' => 'Please refresh the page and try again',
    'clear_browser_cache' => 'Please clear your browser cache and try again',
    'check_internet_connection' => 'Please check your internet connection and try again',
    'try_different_browser' => 'Please try using a different browser',
    'restart_application' => 'Please restart the application and try again',

    // Specific Error Messages
    'invalid_credentials' => 'Invalid credentials provided',
    'account_locked' => 'Your account has been locked',
    'account_disabled' => 'Your account has been disabled',
    'session_expired' => 'Your session has expired',
    'permission_denied' => 'Permission denied for this action',
    'resource_not_found' => 'The requested resource was not found',
    'resource_already_exists' => 'The resource already exists',
    'resource_in_use' => 'The resource is currently in use',
    'invalid_file_format' => 'Invalid file format',
    'file_too_large' => 'File size exceeds the maximum limit',
    'invalid_email_format' => 'Invalid email format',
    'password_too_weak' => 'Password is too weak',
    'username_taken' => 'Username is already taken',
    'email_already_registered' => 'Email is already registered',
    'invalid_token' => 'Invalid or expired token',
    'rate_limit_exceeded' => 'Rate limit exceeded. Please try again later',

    // Database Errors
    'database_connection_failed' => 'Failed to connect to the database',
    'database_query_failed' => 'Database query failed',
    'database_transaction_failed' => 'Database transaction failed',
    'database_constraint_violation' => 'Database constraint violation',
    'database_deadlock' => 'Database deadlock detected',
    'database_timeout' => 'Database operation timed out',

    // Network Errors
    'network_unreachable' => 'Network is unreachable',
    'connection_refused' => 'Connection was refused',
    'connection_reset' => 'Connection was reset',
    'connection_aborted' => 'Connection was aborted',
    'host_unreachable' => 'Host is unreachable',
    'dns_lookup_failed' => 'DNS lookup failed',

    // Security Errors
    'access_denied' => 'Access denied',
    'forbidden_action' => 'This action is forbidden',
    'insufficient_permissions' => 'Insufficient permissions',
    'security_violation' => 'Security violation detected',
    'suspicious_activity' => 'Suspicious activity detected',
    'account_compromised' => 'Account security compromised',

    // Form Errors
    'form_validation_failed' => 'Form validation failed',
    'required_field_missing' => 'Required field is missing',
    'invalid_field_value' => 'Invalid field value',
    'field_too_short' => 'Field value is too short',
    'field_too_long' => 'Field value is too long',
    'field_format_invalid' => 'Field format is invalid',
    'field_already_exists' => 'Field value already exists',
    'field_confirmation_mismatch' => 'Field confirmation does not match',

    // File Operation Errors
    'file_not_found' => 'File not found',
    'file_cannot_be_read' => 'File cannot be read',
    'file_cannot_be_written' => 'File cannot be written',
    'file_cannot_be_deleted' => 'File cannot be deleted',
    'file_cannot_be_moved' => 'File cannot be moved',
    'file_cannot_be_copied' => 'File cannot be copied',
    'file_permission_denied' => 'File permission denied',
    'file_is_corrupted' => 'File is corrupted',
    'file_is_empty' => 'File is empty',
    'file_type_not_supported' => 'File type is not supported',

    // Email Errors
    'email_send_failed' => 'Failed to send email',
    'email_invalid_recipient' => 'Invalid email recipient',
    'email_invalid_sender' => 'Invalid email sender',
    'email_template_not_found' => 'Email template not found',
    'email_attachment_failed' => 'Failed to attach file to email',
    'email_queue_failed' => 'Failed to queue email',

    // Notification Errors
    'notification_send_failed' => 'Failed to send notification',
    'notification_template_not_found' => 'Notification template not found',
    'notification_channel_unavailable' => 'Notification channel is unavailable',
    'notification_recipient_invalid' => 'Invalid notification recipient',

    // API Errors
    'api_endpoint_not_found' => 'API endpoint not found',
    'api_method_not_allowed' => 'API method not allowed',
    'api_authentication_failed' => 'API authentication failed',
    'api_authorization_failed' => 'API authorization failed',
    'api_rate_limit_exceeded' => 'API rate limit exceeded',
    'api_invalid_request' => 'Invalid API request',
    'api_server_error' => 'API server error',

    // Maintenance Errors
    'maintenance_mode_active' => 'Maintenance mode is active',
    'maintenance_scheduled' => 'Maintenance is scheduled',
    'maintenance_estimated_duration' => 'Estimated maintenance duration',
    'maintenance_reason' => 'Maintenance reason',

    // Generic Error Messages
    'unknown_error' => 'An unknown error occurred',
    'unhandled_exception' => 'An unhandled exception occurred',
    'internal_server_error' => 'Internal server error',
    'service_unavailable' => 'Service is temporarily unavailable',
    'bad_request' => 'Bad request',
    'not_found' => 'Not found',
    'method_not_allowed' => 'Method not allowed',
    'request_timeout' => 'Request timeout',
    'conflict' => 'Conflict occurred',
    'gone' => 'Resource is no longer available',
    'length_required' => 'Length required',
    'precondition_failed' => 'Precondition failed',
    'payload_too_large' => 'Payload too large',
    'uri_too_long' => 'URI too long',
    'unsupported_media_type' => 'Unsupported media type',
    'range_not_satisfiable' => 'Range not satisfiable',
    'expectation_failed' => 'Expectation failed',
    'im_a_teapot' => 'I\'m a teapot',
    'misdirected_request' => 'Misdirected request',
    'unprocessable_entity' => 'Unprocessable entity',
    'locked' => 'Resource is locked',
    'failed_dependency' => 'Failed dependency',
    'too_early' => 'Too early',
    'upgrade_required' => 'Upgrade required',
    'precondition_required' => 'Precondition required',
    'too_many_requests' => 'Too many requests',
    'request_header_fields_too_large' => 'Request header fields too large',
    'unavailable_for_legal_reasons' => 'Unavailable for legal reasons',
    'internal_server_error' => 'Internal server error',
    'not_implemented' => 'Not implemented',
    'bad_gateway' => 'Bad gateway',
    'service_unavailable' => 'Service unavailable',
    'gateway_timeout' => 'Gateway timeout',
    'http_version_not_supported' => 'HTTP version not supported',
    'variant_also_negotiates' => 'Variant also negotiates',
    'insufficient_storage' => 'Insufficient storage',
    'loop_detected' => 'Loop detected',
    'not_extended' => 'Not extended',
    'network_authentication_required' => 'Network authentication required',
    'page_expired' => 'The page has expired. Please refresh and try again.',
];
