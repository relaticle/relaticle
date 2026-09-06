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
            'digest' => 'You received this because you enabled the daily digest.',
            'assignee' => 'You received this because a task in :team was assigned to you.',
            'invitee' => 'You received this because :email was invited to :team.',
            'contact' => 'You received this because someone submitted the contact form.',
            'account' => 'You received this because of a request on your :company account.',
            'onboarding' => 'You received this because you created a :company workspace.',
        ],
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
        'cta' => 'Continue in Rela',
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
