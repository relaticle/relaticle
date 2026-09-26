<?php

declare(strict_types=1);

return [
    'title' => 'Outbox',
    'columns' => [
        'recipients' => 'Recipients',
        'scheduled_for' => 'Scheduled for',
    ],
    'actions' => [
        'reschedule_field' => 'Send at',
        'bulk_cancel' => 'Cancel selected',
    ],
    'notifications' => [
        'cancelled' => 'Cancelled',
        'rescheduled' => 'Rescheduled',
        'retry_queued' => 'Retry queued',
        'bulk_cancelled' => 'Cancelled :count emails',
        'bulk_cancelled_with_skipped' => 'Cancelled :cancelled, skipped :skipped already sending',
    ],
];
