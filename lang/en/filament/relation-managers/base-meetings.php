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

    'link_to_record' => [
        'label' => 'Link to record',
        'notifications' => [
            'linked' => [
                'title' => 'Meeting linked successfully.',
            ],
        ],
    ],

    'unlink_from_record' => [
        'label' => 'Unlink from this record',
        'notifications' => [
            'unlinked' => [
                'title' => 'Meeting unlinked successfully.',
            ],
        ],
    ],

    'detail' => [
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
];
