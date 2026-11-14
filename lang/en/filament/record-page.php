<?php

declare(strict_types=1);

return [
    'sections' => [
        'details' => 'Details',
        'record_info' => 'Record info',
    ],
    'fields' => [
        'created_by' => 'Created by',
        'created_at' => 'Created',
        'updated_at' => 'Last updated',
    ],
    'actions' => [
        'copy_page_url' => 'Copy page URL',
        'copy_record_id' => 'Copy record ID',
        'merge_record' => 'Merge record',
        'new_note' => 'New note',
        'new_task' => 'New task',
        'open_preview' => 'Open preview',
        'open_record' => 'Open record',
    ],
    'preview' => [
        'previous' => 'Previous record',
        'next' => 'Next record',
        'position' => ':position of :total in :label',
    ],
    'overview' => [
        'label' => 'Overview',
        'highlights' => 'Highlights',
        'activity' => 'Activity',
        'view_all' => 'View all',
        'notes' => 'Notes',
        'tasks' => 'Tasks',
        'emails' => 'Emails',
        'next_task' => 'Next due task',
        'no_next_task' => 'No open tasks',
        'today' => 'Today',
        'tomorrow' => 'Tomorrow',
        'last_interaction' => 'Last interaction',
        'no_interaction' => 'No interaction yet',
    ],
    'notifications' => [
        'url_copied' => 'URL copied to clipboard',
        'id_copied' => 'Record ID copied to clipboard',
    ],
    'merge' => [
        'heading' => 'Merge',
        'notice' => 'The record on the right wins where both records hold a value.',
        'search' => 'Search',
        'swap' => 'Swap records',
        'submit' => 'Merge records',
        'merged' => 'Records merged',
        'unavailable' => 'That record is no longer available to merge.',
        'over_limit' => ':field can hold :max values. Together these records have more. Remove some first.',
    ],
    'empty' => '—',
    'view_all' => 'View all',
    'show_less' => 'Show less',
    'resize_details' => 'Resize details panel',
];
