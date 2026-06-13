<?php

declare(strict_types=1);

return [
    'form' => [
        'name' => [
            'label' => 'Name',
        ],
        'email' => [
            'label' => 'Email',
        ],
        'profile_photo' => [
            'label' => 'Photo',
        ],
        'current_password' => [
            'label' => 'Current Password',
        ],
        'new_password' => [
            'label' => 'New Password',
        ],
        'confirm_password' => [
            'label' => 'Confirm Password',
        ],
        'password' => [
            'label' => 'Password',
        ],
    ],

    'sections' => [
        'update_profile_information' => [
            'title' => 'Profile Information',
            'description' => 'Update your account\'s profile information and email address.',
        ],
        'update_password' => [
            'title' => 'Update Password',
            'description' => 'Ensure your account is using a long, random password to stay secure.',
        ],
        'set_password' => [
            'title' => 'Set Password',
            'description' => 'Add a password to your account so you can also sign in with your email and password.',
        ],
        'browser_sessions' => [
            'title' => 'Browser Sessions',
            'description' => 'Manage and log out your active sessions on other browsers and devices.',
            'notice' => 'If necessary, you may log out of all of your other browser sessions across all of your devices. Some of your recent sessions are listed below; however, this list may not be exhaustive. If you feel your account has been compromised, you should also update your password.',
            'labels' => [
                'current_device' => 'This device',
                'last_active' => 'Last active',
                'unknown_device' => 'Unknown',
            ],
        ],
        'delete_account' => [
            'title' => 'Delete Account',
            'description' => 'Schedule your account for deletion.',
            'notice' => 'Deleting your account will schedule it for permanent removal after a 30-day grace period. You can cancel the deletion by logging back in at any time before that. After the grace period, all your data will be permanently deleted.',
        ],
        'passkeys' => [
            'title' => 'Passkeys',
            'description' => 'Manage your passkeys for passwordless sign-in.',
            'unsupported' => 'Passkeys are not supported in this browser.',
            'empty' => 'No passkeys yet. Add one to sign in without a password.',
            'added' => 'Added :time',
            'last_used' => 'Last used :time',
            'add_passkey' => 'Add passkey',
            'name_label' => 'Passkey name',
            'name_placeholder' => 'e.g., MacBook Pro, iPhone',
            'password_help' => 'For your security, confirm your account password before adding a passkey.',
            'use_password' => 'Use your password instead',
            'method_hint' => "You'll confirm with Face ID, Touch ID, or your passkey.",
            'confirmed' => 'Confirmed',
            'register' => 'Register passkey',
            'registering' => 'Registering...',
            'waiting' => 'Waiting for passkey…',
            'cancel' => 'Cancel',
            'remove' => 'Remove',
            'removing' => 'Removing…',
            'remove_confirm_title' => 'Remove passkey',
            'remove_confirm' => 'Remove this passkey? You will no longer be able to use it to sign in.',
        ],
    ],

    'actions' => [
        'save' => 'Save',
        'remove_photo' => 'Remove photo',
        'delete_account' => 'Delete Account',
        'log_out_other_browsers' => 'Log Out Other Browser Sessions',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Saved.',
        ],
        'photo_removed' => 'Profile photo removed.',
        'photo_remove_failed' => 'Could not remove your profile photo. Please try again.',
        'logged_out_other_sessions' => [
            'success' => 'All other browser sessions have been logged out successfully.',
        ],
        'delete_account_blocked' => [
            'title' => 'Account deletion blocked',
        ],
        'passkey_removed' => [
            'success' => 'Passkey removed.',
        ],
        'passkey_registration_failed' => [
            'title' => 'Could not add passkey. Please try again.',
        ],
        'passkey_confirmation_failed' => [
            'title' => 'Passkey verification failed. Please try again.',
        ],
        'identity_confirmation_failed' => [
            'title' => 'Identity confirmation failed. Please try again.',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'This will schedule your account for deletion. You will have 30 days to cancel by logging back in. After that, all data will be permanently removed.',
            'notice_no_password' => 'This will schedule your account for deletion. You will have 30 days to cancel by logging back in. After that, all data will be permanently removed.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Log Out Other Browser Sessions',
            'description' => 'Enter your password to confirm you would like to log out of your other browser sessions across all of your devices.',
            'description_no_password' => 'Are you sure you would like to log out of your other browser sessions across all of your devices?',
        ],
    ],

    'edit_profile' => 'Edit Profile',

    'scheduled_deletion_interstitial' => [
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Keep my account',
                'modal_heading' => 'Keep your account?',
                'modal_description' => 'Your scheduled deletion will be cancelled and all your data will be preserved.',
                'modal_submit_label' => 'Yes, keep my account',
            ],
            'logout' => [
                'label' => 'Sign out',
            ],
        ],
    ],
];
