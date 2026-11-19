<?php

declare(strict_types=1);

return [
    'logo' => [
        'description' => 'PNG, JPEG oder WebP, bis zu :max.',
        'upload' => 'Hochladen',
        'replace' => 'Ersetzen',
        'remove' => 'Entfernen',
        'failed' => 'Der Upload ist fehlgeschlagen. Versuchen Sie es erneut.',
    ],
    'errors' => [
        'busy' => 'Der Upload wird verarbeitet. Versuchen Sie es in Kürze erneut.',
        'too_large' => 'Die Datei ist größer als :max MB.',
        'mime_not_allowed' => 'Dateien vom Typ :mime werden nicht akzeptiert. Erlaubt: :allowed.',
        'extension_missing' => 'Geben Sie dem Dateinamen eine Endung. Erlaubt: :allowed.',
        'not_found' => 'Der Upload wurde nicht gefunden oder ist abgelaufen.',
        'rate_limited' => 'Upload-Limit erreicht: 60 Uploads pro Stunde und Arbeitsbereich. Versuchen Sie es später erneut.',
        'invalid_base64' => 'Die Base64-Nutzdaten konnten nicht dekodiert werden.',
        'no_source' => 'Übergeben Sie genau eines von base64 mit filename oder upload_id.',
        'invalid_retention' => 'Die Aufbewahrungsdauer muss mindestens eine Stunde betragen.',
    ],
];
