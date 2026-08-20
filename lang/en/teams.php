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
            'placeholder' => 'name@company.com',
        ],
        'emails' => [
            'label' => 'Send invite to',
            'placeholder' => 'example@email.com',
            'helper' => 'Separate multiple addresses with a comma, a space, or a new line.',
        ],
        'invite_as' => [
            'label' => 'Invite as',
        ],
    ],

    'sections' => [
        'update_team_name' => [
            'title' => 'Workspace Name',
            'description' => 'The workspace\'s name and owner information.',
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
        'invite_people' => 'Invite people',
        'send_invitations' => 'Send invitations',
        'add_another' => 'Add another',
        'invite_link' => 'Invite link',
        'rotate_invite_link' => 'Generate a new link',
        'update_team_role' => 'Manage Role',
        'remove_team_member' => 'Remove',
        'leave_team' => 'Leave',
        'resend_team_invitation' => 'Resend',
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
            'cannot_promote_to_admin' => 'Only the workspace owner can grant or revoke Administrator access.',
            'cannot_leave_team' => 'You may not leave a workspace that you created.',
            'cannot_remove_team_member' => 'You do not have permission to remove this member.',
            'cannot_delete_team' => 'You do not have permission to delete this workspace.',
            'cannot_cancel_team_deletion' => 'You do not have permission to cancel this workspace\'s deletion.',
        ],
        'role_updated' => [
            'success' => 'Role updated.',
        ],
        'invite_link_rotated' => [
            'success' => 'A new invite link was generated. The previous link no longer works.',
        ],
        'resend_throttled' => 'Please wait :seconds seconds before resending.',
        'some_invites_failed' => [
            'title' => 'Some invitations could not be sent',
        ],
        'invite_rate_limited' => [
            'title' => 'Too many invitations sent',
            'body' => 'Please wait :seconds seconds before sending more invitations.',
        ],
    ],

    'validation' => [
        'email_already_invited' => 'This user has already been invited to the workspace.',
        'only_owner_promotes_admins' => 'Only the workspace owner can grant the Administrator role.',
        'no_valid_emails' => 'Enter at least one email address.',
        'too_many_invites' => 'You can invite up to :max people at a time.',
        'remove_members_before_deleting' => 'Remove all members from these workspaces, or delete the workspaces, before deleting your account: :teams',
    ],

    'modals' => [
        'invite_people' => [
            'heading' => 'Invite team members',
        ],
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

    'tabs' => [
        'general' => 'General',
        'members' => 'Members',
        'custom_fields' => 'Custom Fields',
        'billing' => 'Billing',
    ],

    'roles' => [
        'owner' => [
            'label' => 'Owner',
        ],
        'admin' => [
            'description' => 'Administrator users can perform any action.',
        ],
        'editor' => [
            'description' => 'Editor users have the ability to read, create, and update.',
        ],
        'viewer' => [
            'description' => 'Viewer users can read records but cannot create, update, or delete.',
        ],
    ],

    'table' => [
        'expires_in' => 'Expires in :time',
        'members_heading' => 'Members',
        'members_count' => '{1} 1 person has access|[2,*] :count people have access',
        'pending_heading' => 'Pending invitations',
        'pending_count' => '{1} 1 person has been invited and has not joined yet|[2,*] :count people have been invited and have not joined yet',
        'expires' => 'Expires',
        'expired' => 'Expired',
    ],

    'invite_link' => [
        'url' => 'Anyone with this link can join',
        'default_role' => 'Role for people who join with this link',
        'join' => [
            'heading' => 'Join :workspace',
            'body' => 'You have been invited to join the :workspace workspace. Confirm to accept the invitation.',
            'action' => 'Join workspace',
        ],
        'expired' => [
            'heading' => 'Invite Link Expired',
            'body' => 'This invite link has expired. Please ask the workspace owner to share a new link.',
            'action' => 'Go to my workspace',
        ],
    ],

    'mail' => [
        'invitation' => [
            'subject' => ':inviter invited you to :team on Relaticle',
            'subject_without_inviter' => 'You\'ve been invited to join :team on Relaticle',
            'line_with_inviter' => ':inviter has invited you to join the :team workspace on Relaticle with :role access.',
            'line' => 'You\'ve been invited to join the :team workspace on Relaticle with :role access.',
            'action' => 'Accept invitation',
            'expiry' => 'This invitation expires :expiry.',
            'ignore' => 'If you weren\'t expecting this, you can safely ignore this email.',
        ],
    ],

    'pending_for_user' => [
        'heading' => 'You have been invited to join :team',
        'detail_with_inviter' => ':inviter invited you with :role access.',
        'detail' => 'You will join with :role access.',
        'accept' => 'Join workspace',
        'decline' => 'Decline',
        'declined' => 'Invitation declined.',
    ],

    'accept' => [
        'joined' => 'You have joined the :team workspace.',
        'already_member' => 'You are already a member of :team.',
        'no_longer_valid' => 'That invitation is no longer valid. It may have been revoked or it may have expired.',
        'account_deleting' => 'You cannot accept invitations while your account is scheduled for deletion.',
        'team_deleting' => 'This workspace is scheduled for deletion and is not accepting new members.',
        'ready' => [
            'heading' => 'Join :team',
            'body_with_inviter' => ':inviter invited you to join :team with :role access.',
            'body' => 'You have been invited to join :team with :role access.',
            'action' => 'Join :team',
            'decline' => 'Not now',
        ],
        'wrong_account' => [
            'heading' => 'This invitation is for a different account',
            'body' => 'This invitation was sent to :invited, but you are signed in as :current.',
            'switch' => 'Sign out and switch account',
            'stay' => 'Go to my workspace',
        ],
        'expired' => [
            'heading' => 'Invitation no longer valid',
            'body' => 'This invitation has expired or has already been accepted.',
            'action' => 'Go to my workspace',
        ],
    ],
];
