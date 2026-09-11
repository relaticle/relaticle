<?php

declare(strict_types=1);

return [
    'create_team' => [
        'label' => 'Create Workspace',
        'steps' => [
            'workspace' => 'Workspace',
            'attribution' => 'Attribution',
            'use_case' => 'Use case',
        ],
        'actions' => [
            'continue' => 'Continue',
            'get_started' => 'Get started',
            'cancel' => 'Cancel',
            'skip' => 'Skip',
            'back' => 'Back',
        ],

        'step_indicator' => 'Step :current of :total',

        'headings' => [
            'workspace' => 'Create your workspace',
            'attribution' => 'How did you hear about us?',
            'attribution_description' => 'Please select below where you found out about Relaticle. This step is optional.',
            'use_case' => 'Help us customize your workspace',
            'use_case_description' => 'Relaticle is all about empowering you to build the exact CRM you need, no matter how complex.',
            'use_case_hint' => 'Tell us about your use case to get started with templates, or start with a blank canvas.',
        ],
        'form' => [
            'your_name' => [
                'label' => 'Your name',
                'placeholder' => 'Jane Doe',
            ],
            'workspace_name' => [
                'label' => 'Workspace name',
                'placeholder' => 'Acme Corp',
                'default' => 'My workspace',
            ],
            'workspace_handle' => [
                'label' => 'Workspace handle',
                'helper_text' => 'Only lowercase letters, numbers, and hyphens are allowed.',
            ],
            'use_case_label' => 'What will you be using Relaticle for?',
            'use_case_validation_attribute' => 'use case',
            'use_case_context_label' => 'Pick what applies to you.',
            'use_case_context_validation_attribute' => 'use case details',
            'other_use_case_label' => 'What will you track?',
            'other_use_case_placeholder' => 'Candidates, donors, wholesale buyers',
            'other_use_case_validation_attribute' => 'what you track',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Workspace created',
                'body' => 'Your workspace ":name" is ready to go.',
            ],
            'workspace_limit_reached' => [
                'title' => 'Workspace limit reached',
                'body' => 'You already own the maximum number of workspaces. Delete one, or ask to be invited to an existing workspace.',
            ],
        ],
        'validation' => [
            'context_required' => 'Pick at least one option for the selected use case.',
            'context_invalid' => 'One of the picked options does not belong to the selected use case.',
        ],
    ],
];
