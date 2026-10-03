<?php

declare(strict_types=1);

return [
    'label' => 'Send email',

    'notifications' => [
        'no_recipients' => [
            'title' => 'No valid recipients',
            'body' => 'None of the selected people have an email address.',
        ],
        'skipped' => [
            'title' => 'Some recipients skipped',
            'body' => '{1}1 selected record has no email address and was not added.|[2,*]:count selected records have no email address and were not added.',
        ],
    ],
];
