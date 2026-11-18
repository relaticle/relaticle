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
            'created' => ':count créés',
            'updated' => ':count mis à jour',
            'skipped' => ':count ignorés',
            'failed' => ':count échoués',
        ],
        'actions' => [
            'view' => 'Voir les imports',
            'download' => 'Télécharger les lignes en échec',
        ],
    ],
];
