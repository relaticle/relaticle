<?php

declare(strict_types=1);

return [
    'create_team' => [
        'label' => 'Create Workspace',
        'steps' => [
            'workspace' => 'Workspace',
            'attribution' => 'Attribution',
            'use_case' => 'Use case',
            'invite' => 'Invite',
        ],
        'actions' => [
            'continue' => 'Continue',
            'send_invites' => 'Send invites',
            'get_started' => 'Get started',
            'copy_invite_link' => 'Copy invite link',
            'add_more' => 'Add more',
            'cancel' => 'Cancel',
            'skip' => 'Skip',
            'skip_for_now' => 'Skip for now',
            'back' => 'Back',
            'go_to_workspace' => 'Go to workspace',
        ],

        'step_indicator' => 'Step :current of :total',

        'headings' => [
            'workspace' => 'Create your workspace',
            'attribution' => 'How did you hear about us?',
            'attribution_description' => 'Please select below where you found out about Relaticle. This step is optional.',
            'use_case' => 'Help us customize your workspace',
            'use_case_description' => 'Relaticle is all about empowering you to build the exact CRM you need, no matter how complex.',
            'use_case_hint' => 'Tell us about your use case to get started with templates, or start with a blank canvas.',
            'invite' => 'Collaborate with your team',
            'invite_description' => 'The more your teammates use Relaticle, the more powerful it becomes.',
            'invite_subheading' => 'Invite your team to collaborate',
        ],
        'form' => [
            'company_name' => [
                'label' => 'Company name',
                'placeholder' => 'Acme Corp',
            ],
            'workspace_handle' => [
                'label' => 'Workspace handle',
                'helper_text' => 'Only lowercase letters, numbers, and hyphens are allowed.',
            ],
            'use_case_label' => 'What will you be using Relaticle for?',
            'use_case_context_label' => 'Please tell us more about your use case.',
            'use_case_validation_attribute' => 'use case',
            'use_case_context_validation_attribute' => 'use case details',
            'invite_email_placeholder' => 'name@company.com',
            'invite_role_member' => 'Member',
            'invite_role_admin' => 'Admin',
            'invite_table_column_email' => 'Email',
            'invite_table_column_role' => 'Role',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Workspace created',
                'body' => 'Your workspace ":name" is ready to go.',
            ],
            'invite_link_copied' => [
                'title' => 'Invite link copied',
                'body' => 'Share this link with your teammates. Anyone with the link can join this team.',
            ],
            'complete_previous_steps' => [
                'title' => 'Complete the previous steps first',
                'body' => 'Fill in your workspace details and use case before generating an invite link.',
            ],
            'workspace_limit_reached' => [
                'title' => 'Workspace limit reached',
                'body' => 'You already own the maximum number of workspaces. Delete one, or ask to be invited to an existing workspace.',
            ],

            'some_invites_failed' => [
                'title' => 'Some invites could not be sent',
                'invalid_email' => 'This is not a valid email address.',
                'generic' => 'Validation failed.',
                'send_failed' => 'The invitation was saved but the email could not be delivered. Resend it from workspace settings.',
                'send_skipped' => 'Not sent, because the mail service is unavailable. Invite this person from workspace settings once it is back.',
            ],
        ],
    ],
];
