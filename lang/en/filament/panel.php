<?php

declare(strict_types=1);

return [
    'actions' => [
        'delete_record' => 'Delete record',
    ],

    'user_menu' => [
        'profile' => 'Profile',
        'settings' => 'Settings',
    ],

    'settings_layout' => [
        'back_to_app' => 'Back to app',
    ],

    'impersonation' => [
        'banner' => 'Signed in as :name (:email) for support.',
        'stop' => 'Stop',
    ],

    'navigation_groups' => [
        'tasks' => 'Tasks',
    ],

    'sidebar' => [
        'resize' => 'Resize sidebar',
    ],

    'tables' => [
        'resize_column' => 'Resize column',
        'resize_column_hint' => 'Drag to resize. Double-click to fit.',
        'column_menu' => [
            'options' => 'Column options',
            'handle_hint' => 'Drag to reorder. Click for options.',
            'move_left' => 'Move left',
            'move_right' => 'Move right',
            'pin' => 'Pin to left',
            'unpin' => 'Unpin',
            'hide' => 'Hide column',
        ],
        'layouts' => [
            'label' => 'Layouts',
            'modified' => '(modified)',
            'mine' => 'My layouts',
            'shared_with_workspace' => 'Shared with workspace',
            'default' => 'My default',
            'name' => 'Name',
            'save' => 'Save',
            'save_as' => 'Save as new layout',
            'rename' => 'Rename',
            'share' => 'Share with workspace',
            'unshare' => 'Stop sharing',
            'share_heading' => 'Share ":name" with the workspace?',
            'share_description' => 'Everyone in :workspace will see this layout and can apply it. Admins can also edit or delete it.',
            'share_confirm' => 'Share',
            'standard' => 'Standard layout',
            'set_default' => 'Set as my default',
            'unset_default' => 'Remove as my default',
            'revert' => 'Revert',
            'delete' => 'Delete layout',
            'delete_heading' => 'Delete ":name"?',
            'delete_description' => 'Members who use this layout keep what their table shows.',
            'saved' => '":name" saved',
            'created' => '":name" saved as a new layout',
            'shared' => '":name" is shared with the workspace',
            'unshared' => '":name" is no longer shared',
            'name_required' => 'Give the layout a name.',
            'name_taken' => 'You already have a layout with this name.',
        ],
    ],

    'payload_too_large' => 'That change is too large to save. Shorten the content and try again.',

    'selects' => [
        'member_self' => ':name (You)',
    ],

    'restore_blocked' => [
        'title' => ':record can\'t be restored',
        'conflict' => ':value in :field now belongs to :holder.',
        'fix' => ':conflict Change or remove it there, then restore.',
        'bulk_title' => '{1} :count record wasn\'t restored|[2,*] :count records weren\'t restored',
        'bulk_line' => ':record: :conflict',
        'conflict_with_unknown_holder' => ':value in :field now belongs to another record.',
    ],
];
