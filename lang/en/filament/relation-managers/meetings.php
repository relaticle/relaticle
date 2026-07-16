<?php

declare(strict_types=1);

return [
    'columns' => [
        'starts_at' => [
            'label' => 'Time',
        ],
        'attendees_count' => [
            'label' => 'Attendees',
        ],
        'response_status' => [
            'label' => 'My RSVP',
        ],
    ],

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

    'actions' => [
        'link_to_record' => [
            'label' => 'Link to record',
        ],
        'unlink_from_record' => [
            'label' => 'Unlink from this record',
        ],
    ],

    'notifications' => [
        'linked' => [
            'title' => 'Meeting linked successfully.',
        ],
        'unlinked' => [
            'title' => 'Meeting unlinked successfully.',
        ],
    ],
];
