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

    'failed' => 'These credentials do not match our records.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    'registration_failed' => 'Registration could not be completed. Please verify your data and try again.',
    'registration_successful' => 'Your account has been created successfully. Please verify your email address.',

    'login' => [
        'title' => 'Login',
        'subtitle' => 'Sign in to your account',
        'email' => 'Email Address',
        'password' => 'Password',
        'remember' => 'Remember Me',
        'forgot' => 'Forgot your password?',
        'submit' => 'Sign In',
        'no_account' => "Don't have an account?",
        'register' => 'Sign up',
        'or' => 'or',
        'with_google' => 'Continue with Google',
        'with_github' => 'Continue with GitHub',
    ],

    'register' => [
        'title' => 'Register',
        'subtitle' => 'Create your account',
        'name' => 'Full Name',
        'username' => 'Username',
        'email' => 'Email Address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm Password',
        'terms' => 'I agree to the :terms and :privacy',
        'submit' => 'Create Account',
        'have_account' => 'Already have an account?',
        'login' => 'Sign in',
        'or' => 'or',
        'with_google' => 'Continue with Google',
        'with_github' => 'Continue with GitHub',
    ],

    'logout' => [
        'title' => 'Logout',
        'message' => 'Are you sure you want to logout?',
        'confirm' => 'Yes, Logout',
        'cancel' => 'Cancel',
    ],

    'verification' => [
        'title' => 'Verify Email Address',
        'message' => 'Before proceeding, please check your email for a verification link.',
        'resend' => 'Resend Verification Email',
        'sent' => 'A fresh verification link has been sent to your email address.',
        'verified' => 'Your email address has been verified successfully.',
    ],

    'password' => [
        'reset' => [
            'title' => 'Reset Password',
            'subtitle' => 'Enter your email address and we will send you a link to reset your password.',
            'email' => 'Email Address',
            'submit' => 'Send Password Reset Link',
            'sent' => 'We have emailed your password reset link.',
            'link' => 'If your account exists, we will send you a password reset link to your email address.',
        ],
        'confirm' => [
            'title' => 'Confirm Password',
            'subtitle' => 'This is a secure area of the application. Please confirm your password before continuing.',
            'password' => 'Password',
            'submit' => 'Confirm',
        ],
        'update' => [
            'title' => 'Update Password',
            'subtitle' => 'Ensure your account is using a long, random password to stay secure.',
            'current' => 'Current Password',
            'new' => 'New Password',
            'confirm' => 'Confirm New Password',
            'submit' => 'Update Password',
            'updated' => 'Your password has been updated successfully.',
        ],
    ],

    'profile' => [
        'title' => 'Profile',
        'subtitle' => 'Update your account profile information and email address.',
        'photo' => 'Profile Photo',
        'remove_photo' => 'Remove Photo',
        'select_photo' => 'Select A New Photo',
        'personal_info' => 'Personal Information',
        'name' => 'Name',
        'email' => 'Email',
        'save' => 'Save',
        'saved' => 'Saved.',
        'delete_account' => 'Delete Account',
        'delete_warning' => 'Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.',
        'delete_confirm' => 'Are you sure you want to delete your account? Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
        'delete_password' => 'Password',
        'delete_submit' => 'Delete Account',
    ],
    'update' => [
        'failed' => 'Profile update failed. Please try again later.',
    ],
    'two_factor' => [
        'title' => 'Two Factor Authentication',
        'subtitle' => 'Add additional security to your account using two factor authentication.',
        'enabled' => 'You have enabled two factor authentication.',
        'disabled' => 'You have not enabled two factor authentication.',
        'description' => 'When two factor authentication is enabled, you will be prompted for a secure, random token during authentication. You may retrieve this token from your phone\'s Google Authenticator application.',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'regenerate' => 'Regenerate Recovery Codes',
        'show_recovery_codes' => 'Show Recovery Codes',
        'recovery_codes' => 'Store these recovery codes in a secure password manager. They can be used to recover access to your account if your two factor authentication device is lost.',
        'confirm' => 'Please confirm access to your account by entering the authentication code provided by your authenticator application.',
        'code' => 'Code',
        'submit' => 'Confirm',
    ],

];
