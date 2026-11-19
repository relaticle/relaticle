<?php

declare(strict_types=1);

return [
    'logo' => [
        'description' => 'PNG, JPEG oder WebP, bis zu :max.',
        'upload' => 'Hochladen',
        'replace' => 'Ersetzen',
        'remove' => 'Entfernen',
        'failed' => 'Das Hochladen ist fehlgeschlagen. Versuchen Sie es erneut.',
    ],
    'errors' => [
        'busy' => 'Das Hochladen wird verarbeitet. Versuchen Sie es in Kürze erneut.',
        'too_large' => 'Die Datei ist größer als :max MB.',
        'mime_not_allowed' => 'Dateien vom Typ :mime werden nicht akzeptiert. Erlaubt: :allowed.',
        'extension_missing' => 'Geben Sie der Dateiname eine Erweiterung. Erlaubt: :allowed.',
        'unreachable' => 'Die URL konnte nicht abgerufen werden.',
        'url_not_allowed' => 'Nur öffentliche https-URLs auf Port 443 können abgerufen werden.',
        'not_found' => 'Das Hochladen wurde nicht gefunden oder ist abgelaufen.',
        'rate_limited' => 'Hochladegrenze erreicht: 60 Hochladungen pro Stunde pro Arbeitsbereich. Versuchen Sie es später erneut.',
        'invalid_base64' => 'Der Base64-Payload konnte nicht dekodiert werden.',
        'no_source' => 'Geben Sie genau eines von source_url, base64 mit Dateiname oder upload_id an.',
        'invalid_retention' => 'Die Retentionszeit muss mindestens eine Stunde betragen.',
    ],
];