<?php

declare(strict_types=1);

return [
    'columns' => [
        'type' => 'Typ',
        'file' => 'Datei',
        'total' => 'Gesamt',
        'created' => 'Erstellt',
        'updated' => 'Aktualisiert',
        'skipped' => 'Übersprungen',
        'failed' => 'Fehlgeschlagen',
        'user' => 'Mitglied',
        'date' => 'Datum',
    ],
    'actions' => [
        'failed_rows' => 'Fehlgeschlagene Zeilen',
        'mark_failed' => 'Als fehlgeschlagen markieren',
        'mark_failed_description' => 'Dieser Import reagiert nicht mehr. Wenn Sie ihn als fehlgeschlagen markieren, können Sie mit derselben Datei einen neuen starten.',
    ],
    'title' => 'Importverlauf',
];
