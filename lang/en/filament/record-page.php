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
        'records_merged' => 'Records merged',
    ],
    'merge' => [
        'heading' => 'Merge',
        'notice' => 'The record on the right wins where both records hold a value.',
        'search' => 'Search',
        'swap' => 'Swap records',
        'submit' => 'Merge records',
        'unavailable' => 'That record is no longer available to merge.',
        'over_limit' => ':field can hold :max values. Together these records have more. Remove some first.',
        'pick' => 'Choose a record',
        'clear' => 'Choose a different record',
        'search_placeholder' => [
            'company' => 'Search by name or domain',
            'people' => 'Search by name or email',
        ],
        'no_results' => 'No records found',
        'no_domains' => 'No domains',
        'no_emails' => 'No emails',
        'record' => 'record',
        'counts' => [
            'people' => '{0} No related people|{1} :count person|[2,*] :count people',
            'opportunities' => '{0} No related opportunities|{1} :count opportunity|[2,*] :count opportunities',
            'contact_opportunities' => '{0} No opportunities as point of contact|{1} :count opportunity as point of contact|[2,*] :count opportunities as point of contact',
            'notes' => '{0} No related notes|{1} :count note|[2,*] :count notes',
            'tasks' => '{0} No related tasks|{1} :count task|[2,*] :count tasks',
        ],
    ],
    'empty' => '—',
    'view_all' => 'View all',
    'show_less' => 'Show less',
    'resize_details' => 'Resize details panel',
];
