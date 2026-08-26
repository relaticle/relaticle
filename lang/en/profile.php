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
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Your profile and sign-in account will be deleted after 30 days. Workspaces that only you belong to will also be deleted. Shared workspace records will remain. Sign in before the deletion date to cancel. Enter your password to confirm.',
            'notice_no_password' => 'Your profile and sign-in account will be deleted after 30 days. Workspaces that only you belong to will also be deleted. Shared workspace records will remain. Sign in before the deletion date to cancel.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Log Out Other Browser Sessions',
            'description' => 'Enter your password to confirm you would like to log out of your other browser sessions across all of your devices.',
            'description_no_password' => 'Are you sure you would like to log out of your other browser sessions across all of your devices?',
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
