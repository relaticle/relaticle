<?php

declare(strict_types=1);

return [
    'warning' => [
        'subject' => ':workspace will be deleted on :date',
        'preheader' => 'No one has signed in since :since. Choose a plan or sign in to keep it.',
        'heading' => ':workspace will be deleted on :date',
        'paused' => ':workspace is paused, and no one has signed in since :since.',
        'rule' => 'We delete paused workspaces after 60 days. On :date we will delete :workspace and every record in it. This cannot be undone.',
        'keep' => 'To keep the workspace, choose a plan before then.',
        'cta' => 'Choose a plan',
        'sign_in' => 'Signing in also keeps it for another 60 days.',
    ],
    'reminder' => [
        'subject' => ':workspace will be deleted on :date',
        'preheader' => 'This is the last reminder. Choose a plan or sign in to keep it.',
        'heading' => ':workspace will be deleted on :date',
        'body' => 'We will delete :workspace and every record in it on :date. This cannot be undone.',
        'keep' => 'Choose a plan or sign in before then to keep it.',
    ],
    'deleted' => [
        'subject' => ':workspace has been deleted',
        'preheader' => 'It was paused for 60 days with no sign-ins.',
        'heading' => ':workspace has been deleted',
        'body' => ':workspace was paused for 60 days with no sign-ins, so we deleted it and its records.',
        'restart' => 'You can sign in at any time and start a new workspace.',
    ],
];
