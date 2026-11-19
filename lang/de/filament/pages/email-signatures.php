<?php

declare(strict_types=1);

return [
    'title' => 'Signaturen',
    'heading' => 'E-Mail-Signaturen',
    'default_badge' => 'Standard',
    'empty' => 'Noch keine Signaturen. Wählen Sie :action, um eine hinzuzufügen.',
    'actions' => [
        'create' => 'Neue Signatur',
        'edit' => 'Bearbeiten',
        'delete' => 'Löschen',
    ],
    'fields' => [
        'connected_account' => 'E-Mail-Konto',
        'name' => 'Signaturname',
        'content' => 'Signaturinhalt',
        'is_default' => 'Als Standard für dieses Konto festlegen',
    ],
    'notifications' => [
        'created' => 'Signatur erstellt.',
        'updated' => 'Signatur aktualisiert.',
        'deleted' => 'Signatur gelöscht.',
    ],
];
