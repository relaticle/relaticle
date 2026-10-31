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
            'edit_label' => 'Edit column label',
            'label' => 'Label',
            'label_hint' => 'Only your table shows this name. Leave it empty to use the original.',
            'save_label' => 'Save',
            'move_left' => 'Move left',
            'move_right' => 'Move right',
            'hide' => 'Hide column',
        ],
        'layouts' => [
            'label' => 'Layouts',
            'modified' => '(modified)',
            'mine' => 'My layouts',
            'shared_with_workspace' => 'Shared with workspace',
            'default' => 'Default',
            'shared_label' => 'Shared',
            'name' => 'Name',
            'save' => 'Save',
            'save_as' => 'Save as new layout',
            'rename' => 'Rename',
            'actions' => 'Actions for :name',
            'share' => 'Share with workspace',
            'unshare' => 'Stop sharing',
            'unshare_heading' => 'Stop sharing ":name"?',
            'unshare_description' => 'It goes back to :owner as a private layout, and you will no longer see it. Members who use it keep what their table shows.',
            'share_heading' => 'Share ":name" with the workspace?',
            'share_description' => 'Everyone in :workspace will see this layout and can apply it. Admins can also edit or delete it.',
            'share_confirm' => 'Share',
            'standard' => 'Standard layout',
            'available' => ':count available',
            'search' => 'Search layouts',
            'save_current' => 'Save current table as a layout',
            'set_default' => 'Set as my default',
            'unset_default' => 'Remove as my default',
            'revert' => 'Revert',
            'delete' => 'Delete layout',
            'delete_heading' => 'Delete ":name"?',
            'delete_description' => 'Members who use this layout keep what their table shows.',
            'delete_description_other' => 'It belongs to :owner, who will be told you deleted it. Members who use it keep what their table shows.',
            'saved' => '":name" saved',
            'created' => '":name" saved as a new layout',
            'deleted' => '":name" deleted',
            'shared' => '":name" is shared with the workspace',
            'unshared' => '":name" is no longer shared',
            'name_required' => 'Give the layout a name.',
            'name_taken' => 'You already have a layout with this name.',
            'unavailable' => 'That layout is no longer available.',
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
