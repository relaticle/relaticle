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
        'timezone' => [
            'label' => 'Timezone',
            'helper_text' => 'Dates and times across the app are shown in this timezone.',
            'placeholder' => 'Select a timezone',
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
            'throttled' => 'Too many attempts. Please try again in :seconds seconds.',
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
            'description' => 'Permanently delete your account after a 30-day grace period.',
            'notice' => 'Your profile and sign-in account will be permanently deleted after 30 days. Workspaces that only you belong to, including their CRM data, will also be deleted. Records in shared workspaces will remain without your profile. Sign in before the deletion date to cancel.',
            'confirm_email_label' => 'Type your account email to confirm',
            'confirm_email_mismatch' => 'That does not match your account email.',
        ],
        'passkeys' => [
            'title' => 'Passkeys',
            'description' => 'Manage your passkeys for passwordless sign-in.',
            'unsupported' => 'Passkeys are not supported in this browser.',
            'empty' => 'No passkeys yet. Add one to sign in without a password.',
            'added' => 'Added :time',
            'last_used' => 'Last used :time',
            'add_passkey' => 'Add passkey',
            'add_description' => 'Register a new passkey for this device to sign in without a password.',
            'name_label' => 'Passkey name',
            'name_placeholder' => 'e.g., MacBook Pro, iPhone',
            'default_name' => 'Passkey',
            'rename' => 'Rename',
            'save' => 'Save',
            'use_password' => 'Use your password instead',
            'method_hint' => "You'll confirm with Face ID, Touch ID, or your passkey.",
            'confirmed' => 'Confirmed',
            'register' => 'Register passkey',
            'registering' => 'Registering...',
            'waiting' => 'Waiting for passkey…',
            'cancel' => 'Cancel',
            'remove' => 'Remove',
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
        'passkey_renamed' => [
            'success' => 'Passkey renamed.',
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
            'notice' => 'Your profile and sign-in account will be deleted after 30 days. Workspaces that only you belong to will also be deleted. Shared workspace records will remain. Sign in before the deletion date to cancel.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Log Out Other Browser Sessions',
            'description' => 'Confirm it\'s you to log out of your other browser sessions across all of your devices.',
        ],
    ],

    'edit_profile' => 'Edit Profile',

    'scheduled_deletion_interstitial' => [
        'heading' => 'Your account is scheduled for deletion',
        'details' => [
            'account' => 'Your profile and sign-in account will be permanently deleted.',
            'deletion_date' => 'Deletion date:',
            'workspaces' => ':count sole-owned workspace and its CRM data will also be deleted.|:count sole-owned workspaces and their CRM data will also be deleted.',
            'shared_records' => 'Records in shared workspaces will remain without your profile.',
        ],
        'help' => 'Changed your mind? Keep your account to cancel this deletion and restore access.',
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Keep my account',
                'modal_heading' => 'Keep your account?',
                'modal_description' => 'Your scheduled deletion will be cancelled. You will regain access to your account and workspaces.',
                'modal_submit_label' => 'Yes, keep my account',
            ],
            'logout' => [
                'label' => 'Sign out',
            ],
        ],
    ],
];
