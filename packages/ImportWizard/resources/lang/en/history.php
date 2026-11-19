<?php

declare(strict_types=1);

return [
    'columns' => [
        'type' => 'Type',
        'file' => 'File',
        'total' => 'Total',
        'created' => 'Created',
        'updated' => 'Updated',
        'skipped' => 'Skipped',
        'failed' => 'Failed',
        'user' => 'User',
        'date' => 'Date',
    ],
    'actions' => [
        'failed_rows' => 'Failed Rows',
        'mark_failed' => 'Mark as failed',
        'mark_failed_description' => 'This import stopped responding. Marking it failed lets you start a new one from the same file.',
    ],
    'title' => 'Import History',
];
