<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Meetings',
    'view' => [
        'heading' => 'Meeting',
    ],
    'time' => [
        'all_day' => 'All day',
    ],
    'sections' => [
        'participants' => [
            'heading' => 'Participants',
            'empty' => 'No participants',
        ],
        'linked_records' => [
            'heading' => 'Linked records',
        ],
        'description' => [
            'heading' => 'Description',
        ],
    ],
    'attendees' => [
        'host' => 'Host',
    ],
    'actions' => [
        'link_records' => [
            'label' => 'Link records',
        ],
    ],
    'linked_record_types' => [
        'people' => 'Person',
        'companies' => 'Company',
        'opportunities' => 'Opportunity',
    ],
    'fields' => [
        'organizer' => [
            'label' => 'Organizer',
        ],
        'email_address' => [
            'label' => 'Email',
        ],
        'html_link' => [
            'label' => 'Open in calendar',
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

    'empty_state' => [
        'heading' => 'No meetings yet',
        'description' => 'Meetings from your synced calendar appear here.',
    ],
];
