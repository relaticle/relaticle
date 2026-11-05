<?php

declare(strict_types=1);

return [
    'warning' => [
        'subject' => ':workspace will be deleted on :date',
        'preheader' => 'It has been paused since :paused with no sign-ins. Choose a plan or sign in to keep it.',
        'heading' => ':workspace will be deleted on :date',
        'paused' => ':workspace has been paused since :paused, and no one has signed in since.',
        'rule' => 'We delete paused workspaces after 60 days. On :date we will delete :workspace and every record in it. This cannot be undone.',
        'keep' => 'To keep the workspace, choose a plan before then.',
        'cta' => 'Choose a plan',
        'sign_in' => 'Signing in also keeps it for another 60 days.',
    ],
];
