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
            'description' => 'Permanently delete your account.',
            'notice' => 'Once your account is deleted, all of its resources and data will be permanently deleted. Before deleting your account, please download any data or information that you wish to retain.',
        ],
    ],

    'actions' => [
        'save' => 'Save',
        'delete_account' => 'Delete Account',
        'log_out_other_browsers' => 'Log Out Other Browser Sessions',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Saved.',
        ],
        'logged_out_other_sessions' => [
            'success' => 'All other browser sessions have been logged out successfully.',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Are you sure you want to delete your account? Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.',
            'notice_no_password' => 'Are you sure you want to delete your account? Once your account is deleted, all of its resources and data will be permanently deleted.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Log Out Other Browser Sessions',
            'description' => 'Enter your password to confirm you would like to log out of your other browser sessions across all of your devices.',
            'description_no_password' => 'Are you sure you would like to log out of your other browser sessions across all of your devices?',
        ],
    ],

    'edit_profile' => 'Edit Profile',
];
