<?php

declare(strict_types=1);

return [
    'heading' => 'Communication Intelligence',
    'groups' => [
        'connection' => 'Connection',
        'email' => 'Email',
        'calendar' => 'Calendar',
    ],
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
            'label' => 'Strongest teammate',
            'placeholder' => 'Nobody',
        ],
        'first_email' => [
            'label' => 'First',
            'placeholder' => 'Never',
        ],
        'last_email' => [
            'label' => 'Last',
            'placeholder' => 'Never',
        ],
        'first_calendar' => [
            'label' => 'First',
            'placeholder' => 'Never',
        ],
        'last_calendar' => [
            'label' => 'Last',
            'placeholder' => 'Never',
        ],
        'next_calendar' => [
            'label' => 'Next',
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
