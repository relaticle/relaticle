<?php

declare(strict_types=1);

return [
    'heading' => [
        'title' => 'Attributs personnalisés',
        'description' => 'Créez et gérez des attributs personnalisés pour saisir des données supplémentaires sur vos enregistrements.',
    ],
    'nav' => [
        'label' => 'Attributs personnalisés',
        'group' => 'Attributs personnalisés',
    ],
    'section' => [
        'form' => [
            'contains_system_fields_cannot_delete' => 'Les sections contenant des attributs définis par le système ne peuvent pas être supprimées.',
        ],
        'notifications' => [
            'duplicate_field_code' => 'Cette section contient déjà un attribut avec ce code. Renommez l\'un des deux avant de le déplacer ici.',
        ],
    ],
    'field' => [
        'form' => [
            'name_helper_text' => 'Le libellé de l\'attribut affiché dans les tableaux et les formulaires.',
            'code_helper_text' => 'Code unique qui identifie cet attribut dans toute la ressource.',
            'description_position_options' => [
                'below' => 'Sous l\'attribut',
                'above' => 'Au-dessus de l\'attribut',
            ],
            'encrypted_help' => 'Si cette option est activée, les valeurs de cet attribut sont stockées de façon chiffrée.',
            'visible_in_list_help' => 'Afficher cet attribut dans les listes en tableau.',
            'list_toggleable_hidden_hint' => 'Si cette option est activée, cet attribut est masqué par défaut dans la vue en liste, mais chacun peut l\'afficher.',
            'visible_in_view_help' => 'Afficher cet attribut sur les pages de détail.',
            'add_field' => 'Ajouter un attribut',
            'search_placeholder' => 'Rechercher des attributs...',
            'type_search_prompt' => 'Rechercher des types d\'attribut...',
            'type_no_results' => 'Aucun type d\'attribut trouvé',
            'system_defined_cannot_delete' => 'Les attributs définis par le système ne peuvent pas être supprimés.',
            'allow_multiple_help' => 'Si cette option est activée, les utilisateurs peuvent saisir plusieurs valeurs pour cet attribut.',
        ],
        'actions' => [
            'edit_modal_heading' => 'Modifier l\'attribut',
            'delete_modal_heading' => 'Supprimer l\'attribut',
            'delete_modal_description' => 'Voulez-vous vraiment supprimer cet attribut ? Cette action est irréversible.',
        ],
    ],
    'empty_states' => [
        'sections' => [
            'description' => 'Créez votre première section pour organiser et regrouper vos attributs personnalisés.',
        ],
        'fields' => [
            'description' => 'Glissez des attributs ici ou cliquez sur le bouton ci-dessous pour ajouter votre premier attribut.',
        ],
        'fields_no_sections' => [
            'heading' => 'Aucun attribut personnalisé pour le moment',
            'description' => 'Cliquez sur le bouton ci-dessous pour ajouter votre premier attribut personnalisé.',
        ],
        'search_no_results' => [
            'heading' => 'Aucun attribut correspondant',
            'description' => 'Aucun attribut ne correspond à votre recherche.',
        ],
    ],
    'common' => [
        'active_fields' => 'Attributs actifs',
        'inactive_fields' => 'Attributs inactifs',
    ],
    'enums' => [
        'condition_source' => [
            'custom_field' => 'Attribut personnalisé',
        ],
        'date_anchor' => [
            'custom_field' => 'Un autre attribut',
        ],
        'description_position' => [
            'below' => 'Sous l\'attribut',
            'above' => 'Au-dessus de l\'attribut',
        ],
        'entity_feature' => [
            'labels' => [
                'custom_fields' => 'Attributs personnalisés',
            ],
            'descriptions' => [
                'custom_fields' => 'Ce type d\'enregistrement peut avoir des attributs personnalisés',
                'lookup_source' => 'Ce type d\'enregistrement peut servir de source de recherche pour les attributs à choix',
                'scoped_management' => 'Les attributs personnalisés de ce type d\'enregistrement sont gérés par enregistrement parent, pas sur la page de gestion globale',
            ],
        ],
    ],
    'date_constraint' => [
        'reference_field' => 'Attribut de référence',
    ],
];
