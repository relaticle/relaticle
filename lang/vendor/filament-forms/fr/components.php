<?php

declare(strict_types=1);

return [
    'builder' => [
        'reordering' => [
            'item' => 'Bloc',
            'position' => ':label, position :position sur :count',
            'moved' => ':label déplacé à la position :position sur :count',
        ],
        'block_picker' => [
            'no_search_results_message' => 'Aucun bloc ne correspond à votre recherche.',
            'search_prompt' => 'Rechercher des blocs',
        ],
    ],
    'checkbox_list' => [
        'required_description' => 'Sélectionnez au moins une option.',
    ],
    'color_picker' => [
        'panel_label' => 'Sélecteur de couleur',
    ],
    'date_time_picker' => [
        'month_select' => [
            'label' => 'Mois',
        ],
        'year_input' => [
            'label' => 'Année',
        ],
        'hour_input' => [
            'label' => 'Heure',
        ],
        'minute_input' => [
            'label' => 'Minute',
        ],
        'second_input' => [
            'label' => 'Seconde',
        ],
    ],
    'file_upload' => [
        'placeholder' => 'Aucun fichier téléversé',
        'actions' => [
            'download' => [
                'label' => 'Télécharger',
            ],
            'open' => [
                'label' => 'Ouvrir dans un nouvel onglet',
            ],
        ],
        'editor' => [
            'label' => 'Éditeur d\'image',
        ],
    ],
    'js_field' => [
        'failed_to_load' => 'Ce champ n\'a pas pu être chargé. Rechargez la page et réessayez.',
    ],
    'key_value' => [
        'actions' => [
            'move_down' => [
                'label' => 'Descendre',
            ],
            'move_up' => [
                'label' => 'Monter',
            ],
        ],
        'columns' => [
            'actions' => [
                'label' => 'Actions',
            ],
            'reorder' => [
                'label' => 'Réorganiser',
            ],
        ],
        'reordering' => [
            'row' => 'Ligne',
            'position' => ':label, position :position sur :count',
            'moved' => ':label déplacé à la position :position sur :count',
        ],
    ],
    'repeater' => [
        'reordering' => [
            'item' => 'Élément',
            'position' => ':label, position :position sur :count',
            'moved' => ':label déplacé à la position :position sur :count',
        ],
        'columns' => [
            'actions' => [
                'label' => 'Actions',
            ],
            'reorder' => [
                'label' => 'Réorganiser',
            ],
        ],
    ],
    'rich_editor' => [
        'actions' => [
            'close_panel' => [
                'label' => 'Fermer le panneau',
            ],
        ],
        'custom_blocks' => [
            'actions' => [
                'delete' => [
                    'label' => 'Supprimer le bloc',
                ],
                'edit' => [
                    'label' => 'Modifier le bloc',
                ],
            ],
            'no_search_results_message' => 'Aucun bloc ne correspond à votre recherche.',
            'search_label' => 'Rechercher des blocs',
            'search_prompt' => 'Rechercher des blocs',
        ],
        'toolbar' => [
            'label' => 'Barre d\'outils de l\'éditeur',
        ],
        'uploading_file_message' => 'Téléversement du fichier...',
    ],
    'select' => [
        'actions' => [
            'clear' => [
                'label' => 'Effacer la sélection',
            ],
            'remove_option' => [
                'label' => 'Retirer :label',
            ],
        ],
        'error_message' => 'Les options n\'ont pas pu être chargées. Veuillez réessayer.',
        'required_description' => 'Sélectionnez une option.',
        'search_label' => 'Rechercher',
    ],
    'tags_input' => [
        'tag_added' => 'Ajouté : :tag',
        'tag_removed' => 'Retiré : :tag',
    ],
    'toggle_buttons' => [
        'required_description' => 'Sélectionnez au moins une option.',
    ],
];
