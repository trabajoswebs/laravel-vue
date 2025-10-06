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
        'message' => 'Thanks for joining us – we’re delighted to help you run the show.',
        'action' => 'Get started',
    ],

    'password_reset' => [
        'title' => 'Password reset request',
        'message' => 'We’ve had a request to reset the password on your account.',
        'action' => 'Reset password',
        'warning' => 'If this wasn’t you, simply ignore this message and your password will stay the same.',
    ],

    'email_verification' => [
        'title' => 'Verify your email address',
        'message' => 'Confirm your email address to finish setting up your account.',
        'action' => 'Verify email',
        'warning' => 'If you didn’t create an account, you can safely ignore this message.',
    ],

    'account_created' => [
        'title' => 'Account created',
        'message' => 'Your account is ready to go. We’re pleased to have you with us.',
        'action' => 'Complete setup',
    ],

    'login_alert' => [
        'title' => 'New login detected',
        'message' => 'Your account has just been accessed from :location using :device.',
        'action' => 'Review activity',
        'warning' => 'If you don’t recognise this, update your password and double-check your security settings.',
    ],

    'security_alert' => [
        'title' => 'Security alert',
        'message' => 'We’ve spotted some unusual activity on your account. Have a look and take action if needed.',
        'action' => 'Review activity',
        'warning' => 'Automatic security notice.',
    ],

    'profile_updated' => [
        'title' => 'Profile updated',
        'message' => 'All the changes to your profile are saved.',
        'action' => 'View profile',
    ],

    'settings_updated' => [
        'title' => 'Settings updated',
        'message' => 'We’ve saved your account settings.',
        'action' => 'View settings',
    ],

    'two_factor_enabled' => [
        'title' => 'Two-step verification enabled',
        'message' => 'Two-step verification is now active on your account.',
        'action' => 'Manage 2FA',
    ],

    'two_factor_disabled' => [
        'title' => 'Two-step verification disabled',
        'message' => 'Two-step verification has been switched off for your account.',
        'action' => 'Enable 2FA',
    ],

    'account_locked' => [
        'title' => 'Account temporarily locked',
        'message' => 'We’ve locked your account after several unsuccessful login attempts.',
        'action' => 'Unlock account',
        'warning' => 'It’s a precaution to keep you secure.',
    ],

    'account_unlocked' => [
        'title' => 'Account unlocked',
        'message' => 'Your account is open again. You can now log in.',
        'action' => 'Log in',
    ],

];
