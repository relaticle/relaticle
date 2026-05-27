<?php

declare(strict_types=1);

return [
    'sharing_preference' => [
        'description' => 'Overrides the workspace default for emails you sync. Set to blank to use the workspace default.',
        'fields' => [
            'default_email_sharing_tier' => [
                'label' => 'Default sharing tier',
                'placeholder' => 'Use workspace default',
            ],
        ],
        'actions' => [
            'save' => [
                'label' => 'Save',
            ],
        ],
    ],

    'blocklist' => [
        'description' => 'Emails involving these addresses or domains will be hidden from your view.',
        'label' => '',
        'fields' => [
            'type' => [
                'label' => 'Type',
            ],
            'value' => [
                'label' => 'Value',
                'placeholder' => 'e.g. spam@example.com or spammy.com',
            ],
        ],
        'actions' => [
            'save' => [
                'label' => 'Save',
            ],
        ],
    ],
];
