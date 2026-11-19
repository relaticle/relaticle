<?php

declare(strict_types=1);

return [
    'title' => 'Signatures',
    'heading' => 'Signatures e-mail',
    'default_badge' => 'Par défaut',
    'empty' => 'Aucune signature pour le moment. Sélectionnez :action pour en ajouter une.',
    'actions' => [
        'create' => 'Nouvelle signature',
        'edit' => 'Modifier',
        'delete' => 'Supprimer',
    ],
    'fields' => [
        'connected_account' => 'Boîte mail',
        'name' => 'Nom de la signature',
        'content' => 'Contenu de la signature',
        'is_default' => 'Définir par défaut pour cette boîte mail',
    ],
    'notifications' => [
        'created' => 'Signature créée.',
        'updated' => 'Signature mise à jour.',
        'deleted' => 'Signature supprimée.',
    ],
];
