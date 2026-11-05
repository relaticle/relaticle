<?php

declare(strict_types=1);

return [
    'notice' => [
        'subject' => 'We now keep paused workspaces for 60 days',
        'preheader' => ':workspace is safe until :date. Choose a plan or sign in to keep it longer.',
        'heading' => 'We now keep paused workspaces for 60 days',
        'body' => ':workspace has been paused since its trial ended. From today, we delete a paused workspace after 60 days without a sign-in.',
        'date' => 'Your 60 days start today. If no one signs in and you choose no plan, we will delete :workspace on :date.',
        'warn' => 'We will email you 14 days and 5 days before that.',
        'cta' => 'Choose a plan',
    ],
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
