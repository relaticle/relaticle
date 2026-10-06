<?php

declare(strict_types=1);

return [
    'create_workspace' => [
        'label' => 'Create Workspace',
        'steps' => [
            'workspace' => 'Workspace',
            'attribution' => 'Attribution',
        ],
        'actions' => [
            'continue' => 'Continue',
            'get_started' => 'Get started',
            'cancel' => 'Cancel',
            'back' => 'Back',
        ],

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
            'company_logo' => [
                'label' => 'Company logo',
            ],
            'workspace_name' => [
                'label' => 'Company name',
                'placeholder' => 'Enter your company name',
            ],
            'workspace_handle' => [
                'label' => 'Workspace handle',
                'placeholder' => 'my-workspace',
                'helper_text' => 'Only lowercase letters, numbers, and hyphens are allowed.',
            ],
            'use_case_label' => 'What will you be using Relaticle for?',
            'use_case_validation_attribute' => 'use case',
            'use_case_context_label' => 'Pick what applies to you.',
            'use_case_context_validation_attribute' => 'use case details',
            'other_use_case_label' => 'What will you track?',
            'other_use_case_placeholder' => 'Candidates, donors, wholesale buyers',
            'other_use_case_validation_attribute' => 'what you track',
            'referral_detail_label' => 'Which assistant was it?',
            'referral_prompt_label' => 'What did you ask it?',
            'referral_prompt_placeholder' => 'An open source CRM for a small sales team',
            'referral_prompt_validation_attribute' => 'what you asked',
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
        'preview' => [
            'company_placeholder' => 'Your company',
        ],
        'validation' => [
            'context_required' => 'Pick at least one option for the selected use case.',
            'context_invalid' => 'One of the picked options does not belong to the selected use case.',
            'referral_detail_invalid' => 'The picked assistant does not belong to the selected source.',
        ],
    ],
    'setup_workspace' => [
        'title' => 'Set up your workspace',
        'email' => [
            'heading' => 'Start with the people you already email',
            'description' => 'Connect your mailbox and Relaticle builds your CRM while you finish setup.',
            'google' => 'Continue with Google',
            'microsoft' => 'Continue with Microsoft',
            'privacy' => 'Only you can read your emails. You choose what your team sees next.',
            'benefit_records' => 'People and companies you email, added for you',
            'benefit_timeline' => 'Emails and meetings on the right record',
            'benefit_send' => 'Send from Relaticle with your own address',
            'skip' => 'Skip for now',
        ],
        'sharing' => [
            'heading' => 'Choose what your team sees',
            'description' => 'Your mailbox is syncing. Only you can read your emails. Pick how they appear to other members.',
            'connected' => 'Connected',
            'footer' => 'You can change this any time in settings.',
        ],
        'preview' => [
            'people' => 'People',
            'person' => 'Person',
            'company' => 'Company',
            'from_mailbox' => 'From your mailbox',
            'syncing' => '{0} Syncing|{1} Syncing, :count person|[2,*] Syncing, :count people',
        ],
    ],
];
