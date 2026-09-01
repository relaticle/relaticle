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
        'add_team_member' => [
            'title' => 'Invite people',
            'description' => 'Send an email invitation, or share a link that lets people join themselves.',
        ],
        'team_members' => [
            'title' => 'Members',
            'description' => 'Everyone with access to this workspace, including people who have not accepted yet.',
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
        'invite_people' => 'Invite team members',
        'send_invitations' => 'Send invitations',
        'invite_link' => 'Invite link',
        'close' => 'Close',
        'rotate_invite_link' => 'Generate a new link',
        'disable_invite_link' => 'Turn off the link',
        'enable_invite_link' => 'Turn on the link',
        'update_team_role' => 'Change role',
        'remove_team_member' => 'Remove',
        'leave_team' => 'Leave',
        'resend_team_invitation' => 'Resend',
        'revoke_team_invitation' => 'Revoke',
        'delete_team' => 'Delete Workspace',
        'cancel_deletion' => 'Cancel Deletion',
    ],

    'notifications' => [
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
        'permission_denied' => [
            'cannot_promote_to_admin' => 'Only the workspace owner can grant or revoke Administrator access.',
            'cannot_remove_team_member' => 'You do not have permission to remove this member.',
            'cannot_delete_team' => 'You do not have permission to delete this workspace.',
            'cannot_cancel_team_deletion' => 'You do not have permission to cancel this workspace\'s deletion.',
        ],
        'role_updated' => [
            'success' => 'Role updated.',
        ],
        'invite_link_role_updated' => [
            'success' => 'Anyone joining with this link is now a :role.',
        ],
        'invite_link_rotated' => [
            'success' => 'A new invite link was generated. The previous link no longer works.',
        ],
        'invite_link_disabled' => [
            'success' => 'The workspace link is off. Invite people by email instead.',
        ],
        'invite_link_enabled' => [
            'success' => 'The workspace link is on. Anyone who opens it can join.',
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
        'email_already_member' => 'This user already belongs to the workspace.',
        'only_owner_promotes_admins' => 'Only the workspace owner can grant the Administrator role.',
        'invite_link_role_cannot_be_admin' => 'The workspace link cannot grant the Administrator role. Invite administrators by email instead.',
        'no_valid_emails' => 'Enter at least one email address.',
        'too_many_invites' => 'You can invite up to :max people at a time.',
        'remove_members_before_deleting' => 'Remove all members from these workspaces, or delete the workspaces, before deleting your account: :teams',
    ],

    'modals' => [
        'leave_team' => [
            'notice' => 'Are you sure you would like to leave this workspace?',
        ],
        'delete_team' => [
            'notice' => 'This will schedule the workspace for deletion. You will have 30 days to cancel before all data is permanently removed.',
        ],
        'rotate_invite_link' => [
            'heading' => 'Generate a new invite link?',
            'notice' => 'The current link stops working immediately. Anyone still holding it, in a chat or an email, will not be able to join.',
        ],
        'disable_invite_link' => [
            'heading' => 'Turn off the workspace link?',
            'notice' => 'Nobody can join with the current link once it is off. Turning it back on issues a different link, so the old one stays dead.',
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
        'import_history' => 'Import History',
        'activity' => 'Activity',
        'billing' => 'Billing',
    ],

    'activity' => [
        'system' => 'System',
        'search_placeholder' => 'Search by record name',
        'record_destroyed' => 'This record has been permanently deleted.',
        'yes' => 'Yes',
        'no' => 'No',
        'columns' => [
            'created_at' => 'When',
            'causer' => 'Who',
            'event' => 'Action',
            'subject_type' => 'Type',
            'record' => 'Record',
            'changes' => 'Changes',
        ],
        'filters' => [
            'event' => 'Action',
            'subject_type' => 'Type',
            'causer' => 'Who',
            'from' => 'From',
            'until' => 'Until',
        ],
        'events' => [
            'created' => 'Created',
            'updated' => 'Updated',
            'deleted' => 'Deleted',
            'restored' => 'Restored',
        ],
        'types' => [
            'company' => 'Company',
            'people' => 'Person',
            'opportunity' => 'Opportunity',
            'task' => 'Task',
            'note' => 'Note',
        ],
        'empty' => [
            'heading' => 'No activity yet',
            'description' => 'Changes your members make to records will show up here.',
        ],
        'changes_modal' => [
            'trigger' => 'View all changes',
            'close' => 'Close',
        ],
        'no_results' => [
            'heading' => 'Nothing matches these filters',
            'description' => 'Try a different search term, or widen the date range.',
            'action' => 'Clear filters',
        ],
    ],

    'roles' => [
        'owner' => [
            'label' => 'Owner',
        ],
        'admin' => [
            'description' => 'Can create, edit, and delete anything in this workspace.',
        ],
        'editor' => [
            'description' => 'Can create and edit records, but not delete them.',
        ],
        'viewer' => [
            'description' => 'Can view records, but not change them.',
        ],
    ],

    'table' => [
        'user' => 'User',
        'role' => 'Role',
        'status' => 'Status',
        'search_placeholder' => 'Search name or email',
        'invite_pending' => 'Invite pending',
        'invite_expired' => 'Invite expired',
        'expires_in' => 'Expires in :time',
        'expired_ago' => 'Expired :time ago',
        'expired' => 'Expired',
        'no_results' => [
            'heading' => 'Nobody matches that search',
            'description' => 'Try part of a name, or the email address you invited.',
        ],
    ],

    'invite_link' => [
        'heading' => 'Invite link',
        'description' => 'Share one link instead of typing addresses. Anyone who opens it joins this workspace.',
        'url' => 'Workspace link',
        'copied' => 'Link copied.',
        'default_role' => 'Role for people who join with this link',
        'default_role_helper' => 'Saved as soon as you pick it. Administrators are invited by email instead.',
        'disabled_notice' => 'The workspace link is off, so email invitations are the only way in. Turning it on issues a new link.',
        'join' => [
            'heading' => 'Join :workspace',
            'body' => 'You will join with :role access.',
            'joining_as' => 'Joining as',
            'members' => '{1} 1 person is already in this workspace|[2,*] :count people are already in this workspace',
            'action' => 'Join workspace',
            'decline' => 'Not now',
        ],
        'expired' => [
            'heading' => 'Invite link expired',
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
