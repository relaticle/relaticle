<?php

declare(strict_types=1);

return [
    'title' => 'Set up your workspace',
    'opens_in_new_tab' => '(opens in a new tab)',
    'assistant_prompt' => 'Move my current clients into Relaticle: add each company and the people I work with there, and an opportunity for every deal that is still open.',
    'buttons' => [
        'add_client' => [
            'label' => 'Add a client',
            'prompt' => 'I want to add a company I work with and the people there. Ask me for the details.',
        ],
        'show_synced' => [
            'label' => 'Show my synced companies',
            'prompt' => 'Show me the companies that synced from my mailbox.',
        ],
        'add_outside_email' => [
            'label' => 'Add someone not in my email',
            'prompt' => 'I want to add a person or company that is not in my email. Ask me for the details.',
        ],
        'upload_file' => [
            'label' => 'Upload my file',
        ],
        'connect_assistant' => [
            'label' => 'Connect Claude or ChatGPT',
            'prompt' => 'How do I connect Claude or ChatGPT to this workspace?',
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
