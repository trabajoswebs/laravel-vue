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

    'failed' => 'We couldn’t find an account with those details.',
    'password' => 'The password you entered isn’t quite right.',
    'throttle' => 'You’ve tried too many times. Please wait :seconds seconds and try again.',

    'registration_failed' => 'We couldn’t finish setting up the account. Double-check the details and try again.',
    'registration_successful' => 'All set. Please confirm your email address to finish the setup.',

    'login' => [
        'title' => 'Log in',
        'subtitle' => 'Access your account',
        'email' => 'Email address',
        'password' => 'Password',
        'remember' => 'Keep me signed in',
        'forgot' => 'Forgotten your password?',
        'submit' => 'Log in',
        'no_account' => 'Need an account?',
        'register' => 'Create one',
        'or' => 'or',
        'with_google' => 'Continue with Google',
        'with_github' => 'Continue with GitHub',
    ],

    'register' => [
        'title' => 'Create account',
        'subtitle' => 'Tell us a little about yourself to get started.',
        'name' => 'First name and surname',
        'username' => 'Username',
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm Password',
        'terms' => 'I agree to the :terms and :privacy',
        'submit' => 'Create account',
        'have_account' => 'Already registered?',
        'login' => 'Log in',
        'or' => 'or',
        'with_google' => 'Continue with Google',
        'with_github' => 'Continue with GitHub',
    ],

    'logout' => [
        'title' => 'Sign out',
        'message' => 'Are you sure you want to sign out?',
        'confirm' => 'Yes, sign out',
        'cancel' => 'Stay logged in',
    ],

    'verification' => [
        'title' => 'Verify your email',
        'message' => 'Have a quick look at your inbox and tap the verification link before you carry on.',
        'resend' => 'Send the email again',
        'sent' => 'We’ve just sent you a fresh verification link.',
        'verified' => 'Your email address is now verified.',
    ],

    'password' => [
        'reset' => [
            'title' => 'Reset your password',
            'subtitle' => 'Pop in the email you signed up with and we’ll send you a reset link.',
            'email' => 'Email address',
            'submit' => 'Send reset link',
            'sent' => 'If the account exists, a reset link is on its way to your inbox.',
            'link' => 'If there’s an account associated with that email, we’ll send a reset link.',
        ],
        'confirm' => [
            'title' => 'Confirm your password',
            'subtitle' => 'For security reasons we need you to confirm your password before you continue.',
            'password' => 'Password',
            'submit' => 'Confirm',
        ],
        'update' => [
            'title' => 'Update your password',
            'subtitle' => 'Keep things secure with a strong password you only use here.',
            'current' => 'Current Password',
            'new' => 'New Password',
            'confirm' => 'Confirm New Password',
            'submit' => 'Update password',
            'updated' => 'Your password is all up to date.',
        ],
    ],

    'profile' => [
        'title' => 'Profile',
        'subtitle' => 'Review and update your personal details whenever you need.',
        'photo' => 'Profile photo',
        'remove_photo' => 'Remove photo',
        'select_photo' => 'Choose a new photo',
        'personal_info' => 'Personal information',
        'name' => 'Name',
        'email' => 'Email',
        'save' => 'Save',
        'saved' => 'Saved',
        'delete_account' => 'Delete account',
        'delete_warning' => 'Deleting your account permanently removes all your data. Download anything important before you continue.',
        'delete_confirm' => 'Enter your password if you’re sure you want to delete your account for good.',
        'delete_password' => 'Password',
        'delete_submit' => 'Delete account',
    ],
    'update' => [
        'failed' => 'We couldn’t update your profile. Please try again shortly.',
        'success' => 'All done – your changes are saved.',
    ],
    'deleted' => [
        'failed' => 'We couldn’t delete your account. Please try again shortly.',
        'success' => 'Your account has been deleted successfully. We hope to see you back soon.',
    ],
    'two_factor' => [
        'title' => 'Two-step verification',
        'subtitle' => 'Add an extra layer of security to your account.',
        'enabled' => 'Two-step verification is switched on.',
        'disabled' => 'Two-step verification is currently off.',
        'description' => 'When it’s enabled, we’ll ask for a one-time code as well as your password. Use your authenticator app (Google Authenticator, Authy, etc.) to get the code.',
        'enable' => 'Enable',
        'disable' => 'Disable',
        'regenerate' => 'Generate new recovery codes',
        'show_recovery_codes' => 'Show recovery codes',
        'recovery_codes' => 'Keep these codes somewhere safe. They’ll help you get back in if you lose your phone.',
        'confirm' => 'Enter the code from your authenticator app to confirm.',
        'code' => 'Code',
        'submit' => 'Confirm',
    ],

];
