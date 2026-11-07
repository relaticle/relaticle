<?php

declare(strict_types=1);

return [
    'title' => 'Set up your workspace',
    'assistant_prompt' => 'Move my current clients into Relaticle: add each company and the people I work with there, and an opportunity for every deal that is still open.',
    'buttons' => [
        'add_client' => [
            'label' => 'Add a client',
            'prompt' => 'I want to add a company I work with and the people there. Ask me for the details.',
        ],
        'add_deal' => [
            'label' => 'Add a deal',
            'prompt' => 'I want to add an opportunity for a company I am working with. Ask me which company and what it is worth.',
        ],
        'show_synced' => [
            'label' => 'Show my synced companies',
            'prompt' => 'Show me the companies that synced from my mailbox.',
        ],
        'add_outside_email' => [
            'label' => 'Add someone not in my email',
            'prompt' => 'I want to add a person or company that is not in my email. Ask me for the details.',
        ],
        'copy_prompt' => [
            'label' => 'Copy the prompt',
            'prompt' => 'Write the prompt I can paste into my AI assistant so it moves my current companies, people and opportunities into Relaticle.',
        ],
        'connect_steps' => [
            'label' => 'Open connection steps',
        ],
        'upload_file' => [
            'label' => 'Upload my file',
        ],
        'pick_first' => [
            'label' => 'Pick who to work on first',
            'prompt' => 'Show me the companies that just arrived so I can pick who to work on first.',
        ],
        'later' => [
            'label' => 'Later',
            'prompt' => 'I will pick who to work on first later.',
        ],
    ],
];
