<?php

declare(strict_types=1);

return [
    'create_team' => [
        'label' => 'Create Team',
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
            'invite_email_placeholder' => 'colleague@company.com',
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
            'some_invites_failed' => [
                'title' => 'Some invites could not be sent',
            ],
        ],
    ],
];
