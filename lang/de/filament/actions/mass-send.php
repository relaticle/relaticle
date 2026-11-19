<?php

declare(strict_types=1);

return [
    'label' => 'E-Mail senden',

    'notifications' => [
        'no_recipients' => [
            'title' => 'Keine gültigen Empfänger',
            'body' => 'Keine der ausgewählten Personen hat eine E-Mail-Adresse.',
        ],
        'skipped' => [
            'title' => 'Einige Empfänger übersprungen',
            'body' => '{1}1 ausgewählter Eintrag hat keine E-Mail-Adresse und wurde nicht hinzugefügt.|[2,*]:count ausgewählte Einträge haben keine E-Mail-Adresse und wurden nicht hinzugefügt.',
        ],
    ],
];
