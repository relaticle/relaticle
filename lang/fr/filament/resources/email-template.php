<?php

declare(strict_types=1);

return [
    'fields' => [
        'name' => [
            'label' => 'Nom du modèle',
        ],
        'subject' => [
            'label' => 'Objet',
        ],
        'body_html' => [
            'label' => 'Corps',
        ],
        'is_shared' => [
            'label' => "Partager avec l'espace de travail",
            'helper_text' => 'Tous les membres de votre espace de travail peuvent utiliser ce modèle.',
        ],
    ],

    'columns' => [
        'subject' => [
            'placeholder' => '—',
        ],
        'is_shared' => [
            'label' => 'Partagé',
        ],
        'creator' => [
            'label' => 'Créé par',
            'placeholder' => '—',
        ],
        'created_at' => [
            'label' => 'Créé le',
        ],
    ],

    'empty' => [
        'heading' => 'Aucun modèle',
        'description' => 'Enregistrez un message que vous envoyez souvent pour le réutiliser.',
    ],

    'actions' => [
        'create' => [
            'label' => 'Nouveau modèle',
        ],
        'edit' => [
            'label' => 'Modifier le modèle',
        ],
        'delete' => [
            'label' => 'Supprimer',
        ],
    ],
];
