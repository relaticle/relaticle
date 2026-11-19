<?php

declare(strict_types=1);

return [
    'start_over' => [
        'label' => 'Start over',
        'heading' => 'Start a new import?',
        'description' => 'Are you sure you want to start a new import? Your current progress will be lost.',
    ],
    'cancel' => 'Cancel',
    'continue' => [
        'label' => 'Continue',
        'heading' => 'Avoid creating duplicate records',
        'submit' => 'Continue without mapping',
        'back' => 'Go Back',
        'or' => ' or ',
        'warning' => "To avoid creating duplicate records, make sure you map a column that uniquely identifies each record.\n\nFor :entity, map a :fields column.",
    ],
    'start_import' => [
        'heading' => 'Start import',
        'description' => 'Are you sure that you want to start running this import?',
        'submit' => 'Start import',
    ],
    'download_failed_rows' => 'Download Failed Rows',
];
