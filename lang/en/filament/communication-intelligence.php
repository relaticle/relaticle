<?php

declare(strict_types=1);

return [
    'heading' => 'Communication Intelligence',
    'fields' => [
        'first_interaction' => [
            'label' => 'First interaction',
            'placeholder' => 'Never',
        ],
        'last_interaction' => [
            'label' => 'Last interaction',
            'placeholder' => 'Never',
        ],
        'connection_strength' => [
            'label' => 'Strength',
        ],
        'strongest_connection' => [
            'label' => 'Closest teammate',
            'placeholder' => 'Nobody',
        ],
        'first_email' => [
            'label' => 'First email',
            'placeholder' => 'Never',
        ],
        'last_email' => [
            'label' => 'Last email',
            'placeholder' => 'Never',
        ],
        'first_calendar' => [
            'label' => 'First meeting',
            'placeholder' => 'Never',
        ],
        'last_calendar' => [
            'label' => 'Last meeting',
            'placeholder' => 'Never',
        ],
        'next_calendar' => [
            'label' => 'Next meeting',
            'placeholder' => 'None',
        ],
    ],
    'connection_strength' => [
        'none' => 'No connection',
        'very_weak' => 'Very weak',
        'weak' => 'Weak',
        'good' => 'Good',
        'strong' => 'Strong',
        'very_strong' => 'Very strong',
    ],
];
