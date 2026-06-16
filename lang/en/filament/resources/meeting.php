<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Meetings',
    'fields' => [
        'organizer' => [
            'label' => 'Organizer',
        ],
        'email_address' => [
            'label' => 'Email',
        ],
        'html_link' => [
            'label' => 'Open in Google Calendar',
        ],
    ],

    'columns' => [
        'starts_at' => [
            'label' => 'Time',
        ],
        'organizer_name' => [
            'label' => 'Organizer',
        ],
        'attendees_count' => [
            'label' => 'Attendees',
        ],
        'people_count' => [
            'label' => 'People',
        ],
        'companies_count' => [
            'label' => 'Companies',
        ],
        'opportunities_count' => [
            'label' => 'Opportunities',
        ],
        'response_status' => [
            'label' => 'My RSVP',
        ],
    ],

    'filters' => [
        'response_status' => [
            'label' => 'My RSVP',
        ],
    ],
];
