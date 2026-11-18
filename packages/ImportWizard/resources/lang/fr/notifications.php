<?php

declare(strict_types=1);

return [
    'finished' => [
        'title' => [
            'completed' => 'Import terminé : :entity',
            'completed_with_failures' => 'Import terminé avec des lignes en échec : :entity',
            'failed' => 'Échec de l\'import : :entity',
        ],
        'counts' => [
            'created' => 'Créés : :count',
            'updated' => 'Mis à jour : :count',
            'skipped' => 'Ignorés : :count',
            'failed' => 'Échoués : :count',
        ],
        'actions' => [
            'view' => 'Voir les imports',
            'download' => 'Télécharger les lignes en échec',
        ],
    ],
];
