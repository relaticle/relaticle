<?php

declare(strict_types=1);

return [
    // `:key` is replaced with the trigger character, rendered as a key cap.
    'placeholder' => 'Type :key to insert a heading, a list, or an image',

    'limit_reached' => 'This field is full. Delete some content to keep writing.',

    'slash_menu' => [
        'no_results' => 'No blocks match ":query"',

        'groups' => [
            'text' => 'Text',
            'lists' => 'Lists',
            'insert' => 'Insert',
        ],

        'items' => [
            'h1' => [
                'label' => 'Heading 1',
                'description' => 'Large section heading',
            ],
            'h2' => [
                'label' => 'Heading 2',
                'description' => 'Medium section heading',
            ],
            'h3' => [
                'label' => 'Heading 3',
                'description' => 'Small section heading',
            ],
            'paragraph' => [
                'label' => 'Body',
                'description' => 'Just start typing with plain text',
            ],
            'bulletList' => [
                'label' => 'Bulleted list',
                'description' => 'Create a simple bulleted list',
            ],
            'orderedList' => [
                'label' => 'Numbered list',
                'description' => 'Create a list with numbering',
            ],
            'blockquote' => [
                'label' => 'Quote',
                'description' => 'Set a passage apart',
            ],
            'codeBlock' => [
                'label' => 'Code',
                'description' => 'Capture a snippet',
            ],
            'table' => [
                'label' => 'Table',
                'description' => 'Lay data out in rows',
            ],
            'details' => [
                'label' => 'Toggle',
                'description' => 'Hide detail behind a summary',
            ],
            'horizontalRule' => [
                'label' => 'Divider',
                'description' => 'Separate sections with a line',
            ],
            'attachFiles' => [
                'label' => 'Image',
                'description' => 'Upload an image',
            ],
        ],
    ],
];
