<?php

declare(strict_types=1);

return [
    'title' => 'Activity log',
    'modal_description' => 'Unified history of emails, notes, and tasks for this record.',
    'close' => 'Close',
    'empty_state' => 'No activity yet.',
    'load_more' => 'Load more',
    'loading' => 'Loading…',
    'scroll_to_load_more' => 'Scroll to load more…',

    'groups' => [
        'this_week' => 'This week',
        'last_week' => 'Last week',
        'week_of' => 'Week of :date',
    ],

    'summary' => [
        'updated' => ':causer updated :subject',
        'changed_field' => ':causer changed :field',
        'changed_attributes' => ':causer changed :count attributes',
        'fallback' => ':causer :verb :subject',
        'this_record' => 'this record',
    ],

    'entry' => [
        'changed' => 'changed',
        'attributes' => ':count attributes',
    ],
];
