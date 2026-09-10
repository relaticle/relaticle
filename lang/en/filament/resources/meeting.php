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
            'empty' => [
                'heading' => 'No linked records',
                'description' => 'Link people, companies, or deals to connect this meeting to your CRM.',
            ],
        ],
        'description' => [
            'heading' => 'Description',
        ],
    ],
    'attendees' => [
        'host' => 'Host',
        'guest' => 'Guest',
        'show_more' => 'Show more',
        'show_less' => 'Show less',
    ],
    'actions' => [
        'link_records' => [
            'label' => 'Link records',
        ],
        'rsvp' => [
            'label' => 'RSVP',
            'accepted' => [
                'label' => 'Accept',
            ],
            'tentative' => [
                'label' => 'Maybe',
            ],
            'declined' => [
                'label' => 'Decline',
                'heading' => 'Decline this meeting?',
                'description' => 'Your calendar is updated to declined. The meeting stays on the calendar for other guests.',
            ],
        ],
    ],
    'linked_record_types' => [
        'people' => 'Person',
        'companies' => 'Company',
        'opportunities' => 'Opportunity',
    ],
    'fields' => [
        'record_type' => [
            'label' => 'Type',
        ],
        'record' => [
            'label' => 'Record',
        ],
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

    'notifications' => [
        'rsvp' => [
            'accepted' => [
                'title' => 'Invitation accepted. Your calendar is updated.',
            ],
            'tentative' => [
                'title' => 'Marked as maybe. Your calendar is updated.',
            ],
            'declined' => [
                'title' => 'Invitation declined. Your calendar is updated.',
            ],
            'failed' => [
                'title' => 'Could not update your RSVP.',
                'body' => 'Reconnect the mailbox and try again.',
            ],
            'not_synced' => [
                'title' => 'Could not update your RSVP.',
                'body' => 'This meeting has not appeared on your calendar yet. Try again in a moment.',
            ],
        ],
    ],

    'empty_state' => [
        'heading' => 'No meetings yet',
        'description' => 'Meetings from your synced calendar appear here.',
    ],
];
