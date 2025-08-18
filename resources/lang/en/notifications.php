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
        'title' => 'Welcome to :app_name!',
        'message' => 'Thank you for joining us. We\'re excited to have you on board!',
        'action' => 'Get Started',
    ],

    'password_reset' => [
        'title' => 'Password Reset Request',
        'message' => 'You are receiving this notification because we received a password reset request for your account.',
        'action' => 'Reset Password',
        'warning' => 'If you did not request a password reset, no further action is required.',
    ],

    'email_verification' => [
        'title' => 'Verify Your Email Address',
        'message' => 'Please verify your email address to complete your registration.',
        'action' => 'Verify Email',
        'warning' => 'If you did not create an account, no further action is required.',
    ],

    'account_created' => [
        'title' => 'Account Created Successfully',
        'message' => 'Your account has been created successfully. Welcome to :app_name!',
        'action' => 'Complete Setup',
    ],

    'login_alert' => [
        'title' => 'New Login Detected',
        'message' => 'We detected a new login to your account from :location on :device.',
        'action' => 'View Activity',
        'warning' => 'If this wasn\'t you, please secure your account immediately.',
    ],

    'security_alert' => [
        'title' => 'Security Alert',
        'message' => 'We detected suspicious activity on your account. Please review and take action if necessary.',
        'action' => 'Review Activity',
        'warning' => 'This is an automated security notification.',
    ],

    'profile_updated' => [
        'title' => 'Profile Updated',
        'message' => 'Your profile has been updated successfully.',
        'action' => 'View Profile',
    ],

    'settings_updated' => [
        'title' => 'Settings Updated',
        'message' => 'Your account settings have been updated successfully.',
        'action' => 'View Settings',
    ],

    'two_factor_enabled' => [
        'title' => 'Two-Factor Authentication Enabled',
        'message' => 'Two-factor authentication has been enabled for your account.',
        'action' => 'Manage 2FA',
    ],

    'two_factor_disabled' => [
        'title' => 'Two-Factor Authentication Disabled',
        'message' => 'Two-factor authentication has been disabled for your account.',
        'action' => 'Enable 2FA',
    ],

    'account_locked' => [
        'title' => 'Account Temporarily Locked',
        'message' => 'Your account has been temporarily locked due to multiple failed login attempts.',
        'action' => 'Unlock Account',
        'warning' => 'This is a security measure to protect your account.',
    ],

    'account_unlocked' => [
        'title' => 'Account Unlocked',
        'message' => 'Your account has been unlocked successfully. You can now log in.',
        'action' => 'Login',
    ],

];
