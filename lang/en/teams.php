<?php

declare(strict_types=1);

return [
    'form' => [
        'team_name' => [
            'label' => 'Team Name',
        ],
        'team_slug' => [
            'label' => 'Team Slug',
            'helper_text' => 'Only lowercase letters, numbers, and hyphens. This appears in your team URL.',
        ],
        'email' => [
            'label' => 'Email',
        ],
    ],

    'sections' => [
        'update_team_name' => [
            'title' => 'Team Name',
            'description' => 'The team\'s name and owner information.',
        ],
        'add_team_member' => [
            'title' => 'Add Team Member',
            'description' => 'Add a new team member to your team, allowing them to collaborate with you.',
            'notice' => 'Please provide the email address of the person you would like to add to this team.',
        ],
        'team_members' => [
            'title' => 'Team Members',
            'description' => 'All of the people that are part of this team.',
        ],
        'pending_team_invitations' => [
            'title' => 'Pending Team Invitations',
            'description' => 'These people have been invited to your team and have been sent an invitation email. They may join the team by accepting the email invitation.',
        ],
        'delete_team' => [
            'title' => 'Delete Team',
            'description' => 'Schedule this team for deletion.',
            'notice' => 'Deleting this team will schedule it for permanent removal after a 30-day grace period. You can cancel the deletion at any time before that. After the grace period, all resources and data will be permanently deleted.',
            'scheduled_notice' => 'This team is scheduled for deletion on :date.',
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
        'delete_team' => 'Delete Team',
        'cancel_deletion' => 'Cancel Deletion',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Saved.',
        ],
        'team_invitation_sent' => [
            'success' => 'Team invitation sent.',
        ],
        'team_invitation_revoked' => [
            'success' => 'Team invitation revoked.',
        ],
        'invite_link_copied' => [
            'success' => 'Invite link copied to clipboard.',
        ],
        'team_member_removed' => [
            'success' => 'You have removed this team member.',
        ],
        'leave_team' => [
            'success' => 'You have left the team.',
        ],
        'team_deleted' => [
            'success' => 'Team deleted!',
        ],
        'permission_denied' => [
            'cannot_update_team_member' => 'You do not have permission to update this team member.',
            'cannot_leave_team' => 'You may not leave a team that you created.',
            'cannot_remove_team_member' => 'You do not have permission to remove this team member.',
            'cannot_delete_team' => 'You do not have permission to delete this team.',
            'cannot_cancel_team_deletion' => 'You do not have permission to cancel this team\'s deletion.',
        ],
    ],

    'validation' => [
        'email_already_invited' => 'This user has already been invited to the team.',
    ],

    'modals' => [
        'leave_team' => [
            'notice' => 'Are you sure you would like to leave this team?',
        ],
        'delete_team' => [
            'notice' => 'This will schedule the team for deletion. You will have 30 days to cancel before all data is permanently removed.',
        ],
        'cancel_deletion' => [
            'heading' => 'Cancel team deletion?',
            'notice' => 'The team and all its data will be preserved.',
        ],
    ],

    'edit_team' => 'Edit Team',
];
