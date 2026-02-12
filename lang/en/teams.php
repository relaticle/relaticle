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
            'description' => 'Permanently delete this team.',
            'notice' => 'Once a team is deleted, all of its resources and data will be permanently deleted. Before deleting this team, please download any data or information that you wish to retain.',
        ],
    ],

    'actions' => [
        'save' => 'Save',
        'add_team_member' => 'Add',
        'update_team_role' => 'Manage Role',
        'remove_team_member' => 'Remove',
        'leave_team' => 'Leave',
        'resend_team_invitation' => 'Resend',
        'cancel_team_invitation' => 'Cancel',
        'delete_team' => 'Delete Team',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Saved.',
        ],
        'team_invitation_sent' => [
            'success' => 'Team invitation sent.',
        ],
        'team_invitation_cancelled' => [
            'success' => 'Team invitation cancelled.',
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
            'notice' => 'Are you sure you want to delete this team? Once a team is deleted, all of its resources and data will be permanently deleted.',
        ],
    ],

    'edit_team' => 'Edit Team',
];
