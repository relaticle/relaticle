<?php

declare(strict_types=1);

return [
    'label' => 'E-Mail senden',

    'notifications' => [
        'no_recipients' => [
            'title' => 'Keine gültigen Empfänger',
            'body' => 'Keine der ausgewählten Personen haben eine E-Mail-Adresse.',
        ],
        'skipped' => [
            'title' => 'Einige Empfänger übersprungen',
            'body' => '{1}1 ausgewählte Aufzeichnung hat keine E-Mail-Adresse und wurde nicht hinzugefügt.|[2,*]:count ausgewählte Aufzeichnungen haben keine E-Mail-Adresse und wurden nicht hinzugefügt.',
        ],
    ],
];
