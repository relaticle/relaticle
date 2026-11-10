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
            'options_for' => ':column options',
            'edit_label' => 'Edit column label',
            'label' => 'Label',
            'label_hint' => 'Only you see this name until you save it in a view you share. Leave it empty to use the original.',
            'save_label' => 'Save',
            'move_left' => 'Move left',
            'move_right' => 'Move right',
            'pin' => 'Pin to left',
            'unpin' => 'Unpin',
            'hide' => 'Hide column',
        ],
        'views' => [
            'label' => 'Views',
            'switch' => ':name, switch view',
            'modified' => '(modified)',
            'mine' => 'My views',
            'shared_with_workspace' => 'Shared with workspace',
            'default' => 'Default',
            'shared_label' => 'Shared',
            'name' => 'Name',
            'save' => 'Save',
            'save_as' => 'Save as new view',
            'rename' => 'Rename',
            'rename_description_other' => 'It belongs to :owner, who will be told you renamed it.',
            'save_heading' => 'Save over ":name"?',
            'save_description' => 'It belongs to :owner, who will be told you changed it. Members who use it see the new version on their next visit.',
            'actions' => 'Actions for :name',
            'share' => 'Share with workspace',
            'unshare' => 'Stop sharing',
            'unshare_heading' => 'Stop sharing ":name"?',
            'unshare_description' => 'It goes back to :owner as a private view, and you will no longer see it. Members who use it keep what their table shows.',
            'share_heading' => 'Share ":name" with the workspace?',
            'share_description' => 'Everyone in :workspace will see this view and can apply it. Admins can also edit or delete it.',
            'share_confirm' => 'Share',
            'standard' => 'All :records',
            'search' => 'Search views',
            'save_current' => 'Create new view',
            'set_default' => 'Set as my default',
            'unset_default' => 'Remove as my default',
            'revert' => 'Revert',
            'duplicate' => 'Duplicate',
            'copy_name' => ':name copy',
            'duplicated' => '":name" created',
            'delete' => 'Delete view',
            'delete_heading' => 'Delete ":name"?',
            'delete_description' => 'Members who use this view keep what their table shows.',
            'delete_description_other' => 'It belongs to :owner, who will be told you deleted it. Members who use it keep what their table shows.',
            'saved' => '":name" saved',
            'created' => '":name" saved as a new view',
            'created_shared' => '":name" saved as a new view and shared with the workspace',
            'created_on' => 'Created on :date',
            'created_on_by' => 'Created on :date by :owner',
            'deleted' => '":name" deleted',
            'renamed' => 'Renamed to ":name"',
            'shared' => '":name" is shared with the workspace',
            'unshared' => '":name" is no longer shared',
            'name_required' => 'Give the view a name.',
            'name_taken' => 'You already have a view with this name.',
            'name_taken_by_owner' => ':owner already has a view with this name.',
            'unavailable' => 'That view is no longer available.',
            'types' => [
                'table' => 'Table',
            ],
        ],
    ],

    'bottom_navigation' => [
        'label' => 'Main',
        'more' => 'More',
    ],

    'payload_too_large' => 'That change is too large to save. Shorten the content and try again.',

    'selects' => [
        'member_self' => ':name (You)',
    ],

    'record_chips' => [
        'show_all' => 'Show all :count :label',
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
