<?php

declare(strict_types=1);

use Relaticle\Chat\Settings\ChatSettings;

return [

    /*
    |--------------------------------------------------------------------------
    | Settings Classes
    |--------------------------------------------------------------------------
    |
    | Only the keys this app overrides live here; everything else comes from the
    | package's own config, which `mergeConfigFrom` keeps as the base.
    |
    | Registration is explicit because the package auto-discovers `app/Settings`
    | only, and every settings class in this codebase lives in a package under
    | `packages/<Name>/src/Settings`. An unregistered class still resolves (the
    | container autowires it and it lazily loads itself), which is why this was
    | easy to miss, but it gets no scoped binding, so every resolve is a fresh
    | instance and its own query, and `SETTINGS_CACHE_ENABLED` can never reach it.
    |
    */

    'settings' => [
        ChatSettings::class,
    ],

];
