<?php

declare(strict_types=1);

return [
    'form' => [
        'team_name' => [
            'label' => 'Workspace Name',
        ],
        'team_slug' => [
            'label' => 'Workspace Slug',
            'helper_text' => 'Only lowercase letters, numbers, and hyphens. This appears in your workspace URL.',
        ],
        'email' => [
            'label' => 'Email',
        ],
    ],

    'sections' => [
        'update_team_name' => [
            'title' => 'Workspace Name',
            'description' => 'The workspace\'s name and owner information.',
        ],
        'add_team_member' => [
            'title' => 'Add Member',
            'description' => 'Add a new member to your workspace, allowing them to collaborate with you.',
            'notice' => 'Please provide the email address of the person you would like to add to this workspace.',
        ],
        'team_members' => [
            'title' => 'Members',
            'description' => 'All of the people that are part of this workspace.',
        ],
        'pending_team_invitations' => [
            'title' => 'Pending Invitations',
            'description' => 'These people have been invited to your workspace and have been sent an invitation email. They may join the workspace by accepting the email invitation.',
        ],
        'delete_team' => [
            'title' => 'Delete Workspace',
            'description' => 'Schedule this workspace for deletion.',
            'notice' => 'Deleting this workspace will schedule it for permanent removal after a 30-day grace period. You can cancel the deletion at any time before that. After the grace period, all resources and data will be permanently deleted.',
            'scheduled_notice' => 'This workspace is scheduled for deletion on :date.',
        ],
    ],

    'actions' => [
        'save' => 'Save',
        'add_team_member' => 'Add',
        'update_team_role' => 'Manage Role',
        'remove_team_member' => 'Remove',
        'leave_team' => 'Leave',
        'resend_team_invitation' => 'Resend',
        'copy_invite_link' => 'Copy Link',
        'revoke_team_invitation' => 'Revoke',
        'delete_team' => 'Delete Workspace',
        'cancel_deletion' => 'Cancel Deletion',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Saved.',
        ],
        'team_invitation_sent' => [
            'success' => 'Invitation sent.',
        ],
        'team_invitation_revoked' => [
            'success' => 'Invitation revoked.',
        ],
        'invite_link_copied' => [
            'success' => 'Invite link copied to clipboard.',
        ],
        'team_member_removed' => [
            'success' => 'You have removed this member.',
        ],
        'leave_team' => [
            'success' => 'You have left the workspace.',
        ],
        'team_deleted' => [
            'success' => 'Workspace deleted!',
        ],
        'permission_denied' => [
            'cannot_update_team_member' => 'You do not have permission to update this member.',
            'cannot_leave_team' => 'You may not leave a workspace that you created.',
            'cannot_remove_team_member' => 'You do not have permission to remove this member.',
            'cannot_delete_team' => 'You do not have permission to delete this workspace.',
            'cannot_cancel_team_deletion' => 'You do not have permission to cancel this workspace\'s deletion.',
        ],
    ],

    'validation' => [
        'email_already_invited' => 'This user has already been invited to the workspace.',
    ],

    'modals' => [
        'leave_team' => [
            'notice' => 'Are you sure you would like to leave this workspace?',
        ],
        'delete_team' => [
            'notice' => 'This will schedule the workspace for deletion. You will have 30 days to cancel before all data is permanently removed.',
        ],
        'cancel_deletion' => [
            'heading' => 'Cancel workspace deletion?',
            'notice' => 'The workspace and all its data will be preserved.',
        ],
    ],

    'edit_team' => 'Workspace Settings',

    'roles' => [
        'admin' => [
            'description' => 'Administrator users can perform any action.',
        ],
        'editor' => [
            'description' => 'Editor users have the ability to read, create, and update.',
        ],
    ],
];
