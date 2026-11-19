<?php

declare(strict_types=1);

return [
    // `:key` is replaced with the trigger character, rendered as a key cap.
    'placeholder' => 'Tapez :key pour insérer un titre, une liste ou une image',

    'limit_reached' => 'Ce champ est plein. Supprimez du contenu pour continuer à écrire.',

    'checklist' => [
        'item' => 'Élément de la liste de contrôle : :item',
        'empty_item' => 'Élément de liste de contrôle vide',
    ],

    'selection_toolbar' => [
        'text_style' => 'Style du texte',
    ],

    'slash_menu' => [
        'no_results' => 'Aucun bloc ne correspond à « :query »',

        'groups' => [
            'text' => 'Texte',
            'lists' => 'Listes',
            'insert' => 'Insérer',
        ],

        'items' => [
            'h1' => [
                'label' => 'Titre 1',
                'description' => 'Grand titre de section',
            ],
            'h2' => [
                'label' => 'Titre 2',
                'description' => 'Titre de section moyen',
            ],
            'h3' => [
                'label' => 'Titre 3',
                'description' => 'Petit titre de section',
            ],
            'paragraph' => [
                'label' => 'Corps',
                'description' => 'Commencez à écrire du texte simple',
            ],
            'bulletList' => [
                'label' => 'Liste à puces',
                'description' => 'Créez une liste à puces simple',
            ],
            'orderedList' => [
                'label' => 'Liste numérotée',
                'description' => 'Créez une liste avec numérotation',
            ],
            'taskList' => [
                'label' => 'Liste de contrôle',
                'description' => 'Suivez les étapes avec des cases à cocher',
            ],
            'blockquote' => [
                'label' => 'Citation',
                'description' => 'Mettez un passage en valeur',
            ],
            'codeBlock' => [
                'label' => 'Code',
                'description' => 'Ajoutez un extrait de code',
            ],
            'table' => [
                'label' => 'Tableau',
                'description' => 'Présentez des données en lignes',
            ],
            'details' => [
                'label' => 'Volet repliable',
                'description' => 'Masquez le détail derrière un résumé',
            ],
            'horizontalRule' => [
                'label' => 'Séparateur',
                'description' => 'Séparez les sections par une ligne',
            ],
            'attachFiles' => [
                'label' => 'Image',
                'description' => 'Importez une image',
            ],
        ],
    ],
];
