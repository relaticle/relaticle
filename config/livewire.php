<?php

declare(strict_types=1);

return [
    /*
    |---------------------------------------------------------------------------
    | Payload Guards
    |---------------------------------------------------------------------------
    |
    | Filament's RichEditor entangles a TipTap document JSON with $wire. Form
    | data lives under mountedActions.0.data.<schema>.<field> (5+ segments)
    | and TipTap nests bulletList → listItem → paragraph → mark → text. The
    | default depth of 10 throws MaxNestingDepthExceededException for normal
    | rich-text edits inside Filament action modals. Raising the limit keeps
    | DoS protection while accommodating realistic editor content.
    |
    | The full payload array is repeated here because Laravel's
    | mergeConfigFrom uses a shallow array_merge — omitting any sibling key
    | drops the package default to null and disables that guard.
    */

    'payload' => [
        'max_size' => 1024 * 1024,
        'max_nesting_depth' => 30,
        'max_calls' => 50,
        'max_components' => 20,
    ],
];
