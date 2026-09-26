<?php

declare(strict_types=1);

return [
    'title' => 'Import complete',
    'title_with_issues' => 'Import finished with issues',
    'body' => 'Import complete: :imported.',
    'body_with_issues' => 'Import finished with issues: :imported. :failures',
    'imported_emails' => '{0}:count emails|{1}:count email|[2,*]:count emails',
    'imported_calendar_events' => '{0}:count calendar events|{1}:count calendar event|[2,*]:count calendar events',
    'imported_with_calendar' => ':emails and :events',
    'imported_without_calendar' => ':emails',
    'failed_messages' => "{1}:count message couldn't be imported|[2,*]:count messages couldn't be imported",
    'failed_calendar_events' => "{1}:count calendar event couldn't be imported|[2,*]:count calendar events couldn't be imported",
    'calendar_did_not_finish' => 'Calendar import did not finish.',
    'retry_success' => [
        'title' => 'Import retry complete',
        'body' => 'Previously missing messages from :email are imported. :imported are in Relaticle now.',
    ],
    'actions' => [
        'retry' => 'Retry',
    ],
    'retry' => [
        'queued' => [
            'title' => 'Retry queued.',
            'body' => 'We are retrying what could not be imported. We will notify you when it finishes.',
        ],
        'unavailable' => [
            'title' => 'Retry unavailable',
            'body' => 'No failed imports are available to retry for this import.',
        ],
    ],
    'mail' => [
        'subject' => 'Your mailbox import is complete',
        'subject_with_issues' => 'Your mailbox import finished with issues',
        'retry_subject' => 'Your mailbox import retry succeeded',
        'greeting' => 'Hello :name,',
        'line' => 'Import complete: :imported imported from :email. New mail will keep syncing automatically.',
        'line_with_issues' => 'Import finished with issues: :imported imported from :email. :failures',
        'retry_line' => 'We imported the messages that were missing from :email. :imported are in Relaticle now.',
    ],
];
