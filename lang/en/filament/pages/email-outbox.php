<?php

declare(strict_types=1);

return [
    'title' => 'Outbox',
    'columns' => [
        'subject' => 'Subject',
        'status' => 'Status',
        'last_error' => 'Last error',
        'recipients' => 'Recipients',
        'scheduled_for' => 'Scheduled for',
        'type' => 'Type',
    ],
    'priorities' => [
        'priority' => 'Single email',
        'bulk' => 'Mass send',
    ],
    'batch_statuses' => [
        'queued' => 'Queued',
        'sending' => 'Sending',
        'completed' => 'Completed',
        'partial_failure' => 'Partial failure',
    ],
    'tabs' => [
        'aria' => 'Outbox status',
        'queued' => 'Queued',
        'scheduled' => 'Scheduled',
        'sending' => 'Sending',
        'failed' => 'Failed',
        'sent' => 'Sent in the last 24 hours',
    ],
    'actions' => [
        'cancel' => 'Cancel',
        'reschedule' => 'Reschedule',
        'retry' => 'Retry',
        'reschedule_field' => 'Send at',
        'bulk_cancel' => 'Cancel selected',
    ],
    'notifications' => [
        'cancelled' => 'Cancelled',
        'rescheduled' => 'Rescheduled',
        'retry_queued' => 'Retry queued',
        'bulk_cancelled' => '{1}Cancelled 1 email|[2,*]Cancelled :count emails',
        'bulk_cancelled_with_skipped' => 'Cancelled :cancelled, skipped :skipped already sending',
    ],
];
