<?php

declare(strict_types=1);

return [
    'columns' => [
        'type' => 'Type',
        'file' => 'Fichier',
        'total' => 'Total',
        'created' => 'Créés',
        'updated' => 'Mis à jour',
        'skipped' => 'Ignorés',
        'failed' => 'Échoués',
        'user' => 'Membre',
        'date' => 'Date',
    ],
    'actions' => [
        'failed_rows' => 'Lignes en échec',
        'mark_failed' => 'Marquer comme échoué',
        'mark_failed_description' => 'Cet import ne répond plus. Le marquer comme échoué vous permet d\'en lancer un nouveau avec le même fichier.',
    ],
    'title' => 'Historique des imports',
];
