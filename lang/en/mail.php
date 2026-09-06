<?php

declare(strict_types=1);

return [
    'fallback_link' => 'If the button does not work, copy this link into your browser:',

    'footer' => [
        'settings' => 'Notification settings',
        'unsubscribe' => 'Unsubscribe from the daily digest',
        'copyright' => '© :year :company',
        'reason' => [
            'owner' => 'You received this because you own the :team workspace.',
            'member' => 'You received this because you are a member of :team.',
            'former_member' => 'You received this because you were a member of :team.',
            'digest' => 'You received this because you enabled the daily digest.',
            'assignee' => 'You received this because a task in :team was assigned to you.',
            'invitee' => 'You received this because :email was invited to :team.',
            'contact' => 'You received this because someone submitted the contact form.',
            'account' => 'You received this because of a request on your :company account.',
            'onboarding' => 'You received this because you created a :company workspace.',
        ],
    ],

    'unsubscribe' => [
        'title' => 'Unsubscribe',
        'heading' => 'Stop the daily digest?',
        'body' => 'You will no longer receive the morning task digest at :email. You can turn it back on in notification settings.',
        'confirm' => 'Unsubscribe',
        'done_heading' => 'You are unsubscribed',
        'done_body' => 'The daily digest is off for :email.',
        'settings' => 'Notification settings',
    ],

    'trial_ending' => [
        'subject' => 'Your Pro trial ends in 3 days',
        'preheader' => 'Keep every AI model and 2,000 credits for one flat price',
        'heading' => '3 days left on Pro for :team',
        'ends_on' => 'Your 14-day Pro trial ends on :date.',
        'keeps' => 'Pro keeps every AI model, 2,000 monthly credits, and higher rate limits.',
        'flat_price' => 'There is no per-seat pricing. One flat price covers the whole workspace.',
        'grandfathered' => 'If you do nothing, :team returns to its grandfathered Cloud Free plan. Your data is untouched.',
        'paused' => 'If you do nothing, Cloud access pauses when the trial ends. Your data stays stored, and you can subscribe at any time to pick up where you left off.',
        'cta' => 'Keep Pro',
    ],

    'setup_nudge' => [
        'subject' => 'Your workspace is waiting',
        'preheader' => 'One step gets :team working: :step',
        'heading' => ':name, :team is still empty',
        'step' => 'Next step: :step.',
        'cta' => 'Continue in :assistant',
    ],

    'task_assigned' => [
        'subject' => 'New task: :title',
        'preheader' => 'Assigned to you in :team',
        'preheader_without_team' => 'A task was assigned to you',
        'heading' => 'You have a new task',
        'team_label' => 'Workspace',
        'cta' => 'View task',
    ],

    'task_digest' => [
        'subject' => 'Your tasks for :date',
        'preheader' => ':overdue overdue, :due due today',
        'heading' => "Today's tasks, :name",
        'overdue' => 'Overdue',
        'due_today' => 'Due today',
        'due' => 'Due :date',
        'cta' => 'View all my tasks',
    ],

    'team_invitation' => [
        'subject' => ':inviter invited you to :team',
        'subject_without_inviter' => 'You were invited to :team',
        'preheader' => 'Join :team on Relaticle as :role',
        'heading' => 'Join :team',
        'line_with_inviter' => ':inviter invited you to the :team workspace on Relaticle with :role access.',
        'line' => 'You were invited to the :team workspace on Relaticle with :role access.',
        'expiry' => 'This invitation expires :expiry.',
        'ignore' => 'Not expecting this? Ignore this email.',
        'cta' => 'Accept invitation',
    ],

    'team_deletion_scheduled' => [
        'subject' => ':team is scheduled for deletion',
        'preheader' => 'Deletes on :date. Cancel any time before then',
        'heading' => ':team will be deleted on :date',
        'removes' => 'Contacts, companies, tasks, opportunities, notes, and every other record in :team are removed after that date.',
        'cancel' => 'You can cancel from the workspace settings at any time before then.',
        'cta' => 'Cancel deletion',
    ],

    'team_deletion_reminder' => [
        'subject' => ':team deletes in :days day|:team deletes in :days days',
        'preheader' => 'Last reminder before :date',
        'heading' => ':days day until :team is deleted|:days days until :team is deleted',
        'final' => 'This is the last reminder. Everything in :team is removed after :date.',
        'cancel' => 'You can cancel from the workspace settings at any time before then.',
        'cta' => 'Cancel deletion',
    ],

    'team_deletion_cancelled' => [
        'subject' => ':team deletion cancelled',
        'preheader' => 'Your data is safe',
        'heading' => ':team is staying',
        'body' => 'The scheduled deletion of :team was cancelled. Nothing was removed.',
        'cta' => 'Open :team',
    ],

    'team_member_removed' => [
        'subject' => 'You were removed from :team',
        'preheader' => 'You no longer have access to this workspace',
        'heading' => 'You were removed from :team',
        'body' => 'Your access to :team and its records ended. Your other workspaces are unaffected.',
        'cta' => 'Open Relaticle',
    ],

    'account_deletion_scheduled' => [
        'subject' => 'Your account is scheduled for deletion',
        'preheader' => 'Deletes on :date. Sign in to cancel',
        'heading' => 'Your account will be deleted on :date',
        'removes' => 'Your profile and every workspace you own are removed after that date.',
        'cancel' => 'Changed your mind? Sign in before then and the deletion is cancelled.',
        'cta' => 'Keep my account',
    ],

    'account_deletion_reminder' => [
        'subject' => 'Your account deletes in :days day|Your account deletes in :days days',
        'preheader' => 'Last reminder before :date',
        'heading' => ':days day until your account is deleted|:days days until your account is deleted',
        'final' => 'This is the last reminder. Your account and its data are removed after :date.',
        'cancel' => 'Sign in before then and the deletion is cancelled.',
        'cta' => 'Keep my account',
    ],

    'account_deletion_cancelled' => [
        'subject' => 'Your account is staying',
        'preheader' => 'Deletion cancelled, data untouched',
        'heading' => 'Welcome back, :name',
        'body' => 'The scheduled deletion of your account was cancelled. Nothing was removed.',
        'cta' => 'Open Relaticle',
    ],

    'verify_email' => [
        'subject' => 'Verify your email',
        'preheader' => 'One click finishes signing up',
        'heading' => 'Verify your email address',
        'body' => 'Confirm this address to finish setting up your Relaticle account.',
        'ignore' => 'Did not sign up? Ignore this email.',
        'cta' => 'Verify email',
    ],

    'verify_email_change' => [
        'subject' => 'Confirm your new email',
        'preheader' => 'Confirm :email to finish the change',
        'heading' => 'Confirm :email',
        'body' => 'You asked to use :email for your :company account. Confirm it to finish the change. This link expires in :count minutes.',
        'ignore' => 'Did not ask for this? Ignore this email and your current address stays.',
        'cta' => 'Confirm new email',
    ],

    'email_change_notice' => [
        'subject' => 'Email change requested',
        'preheader' => 'Was this you? Block it if not',
        'heading' => 'Someone asked to change your email to :email',
        'body' => 'Someone signed in to your account asked to change its email. Once :email is confirmed, it becomes the address on your account.',
        'block' => 'If this was not you, block the change now, then sign out of other sessions and change your password.',
        'cta' => 'Block this change',
    ],

    'reset_password' => [
        'subject' => 'Reset your password',
        'preheader' => 'This link expires in :count minutes',
        'heading' => 'Reset your password',
        'body' => 'Choose a new password for your Relaticle account. This link expires in :count minutes.',
        'ignore' => 'Did not ask for a reset? Ignore this email and your password stays.',
        'cta' => 'Reset password',
    ],

    'contact_submission' => [
        'subject' => 'New contact: :name',
        'preheader' => ':company, :email',
        'preheader_without_company' => ':email',
        'heading' => 'New contact form message',
        'name' => 'Name',
        'email' => 'Email',
        'company' => 'Company',
        'cta' => 'Reply to :name',
    ],
];
