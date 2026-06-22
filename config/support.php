<?php

declare(strict_types=1);

return [
    /*
     | Full URL of each Maxforms-hosted support form, set per environment.
     | Relaticle has no form builder of its own, so the Help launcher links out.
     | An unset URL hides its Help menu item; all unset hides the Help control.
     */
    'forms' => [
        'contact' => env('SUPPORT_CONTACT_FORM_URL'),
        'bug' => env('SUPPORT_BUG_FORM_URL'),
        'feature' => env('SUPPORT_FEATURE_FORM_URL'),
    ],
];
