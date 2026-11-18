<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'board' => 'Board',
        'cards' => 'Cartes',
        'opportunity_board' => 'Pipeline',
    ],

    'stage_order_failed' => 'L\'ordre des étapes n\'a pas pu être enregistré.',

    'view_settings' => [
        'panel' => 'Paramètres de la vue board',
        'stage_actions' => 'Actions pour :stage',
        'grouped_by' => 'Regroupé par',
        'lanes' => 'Couloirs',
        'no_lanes' => 'Aucun couloir',
        'no_lanes_short' => 'Aucun',
        'visible_columns' => 'Colonnes visibles',
        'show_column' => 'Afficher :column',
        'last_column' => 'Une colonne reste visible.',
        'no_value_column' => 'Sans :attribute',
        'show_labels' => 'Afficher les libellés des attributs',
        'hide_empty' => 'Masquer les attributs vides',
        'visible_attributes' => 'Attributs visibles',
        'attribute_actions' => 'Actions pour :attribute',
        'remove_attribute' => 'Retirer de la carte',
        'attribute_created_off_card' => ':name créé. Ce type d\'attribut ne s\'affiche pas sur une carte.',
        'columns_grouped_by_created' => ':name créé. Les colonnes sont maintenant regroupées selon cet attribut.',
        'add_attribute' => 'Ajouter un attribut à la vue',
        'search_attributes' => 'Rechercher un attribut (:entity)…',
        'all_attributes_shown' => 'Tous les attributs sont sur la carte',
        'no_attribute_matches' => 'Aucun attribut ne correspond',
    ],

    'card_attributes' => [
        'description' => 'Description',
        'checklist' => 'Liste de contrôle',
        'empty' => 'Vide',
    ],

    'stage_menu' => [
        'nouns' => [
            'stage' => 'étape',
            'status' => 'statut',
            'option' => 'option',
        ],
        'add' => 'Ajouter une colonne (:noun)',
        'add_placeholder' => 'Nom de la nouvelle colonne (:noun)',
        'name_taken' => '« :name » existe déjà (:noun).',
        'collapse' => 'Réduire la colonne (:noun)',
        'hide' => 'Masquer la colonne (:noun)',
        'name' => 'Nom de la colonne (:noun)',
        'color' => 'Changer la couleur de la colonne (:noun)',
        'set_color' => 'Définir la couleur sur :color',
        'confetti' => 'Confettis',
        'confetti_label' => 'Lancer des confettis quand une carte arrive ici (:noun)',
        'last_visible' => 'Une colonne (:noun) reste visible. Affichez-en une autre avant de masquer celle-ci.',
        'delete' => 'Supprimer la colonne (:noun)',
        'delete_heading' => 'Supprimer « :name » ?',
        'delete_body' => '{1} :count :records n\'aura plus de valeur pour :attribute. Elle apparaît dans la colonne :column lorsqu\'elle est activée dans les paramètres de la vue.|[2,*] :count :records n\'auront plus de valeur pour :attribute. Elles apparaissent dans la colonne :column lorsqu\'elle est activée dans les paramètres de la vue.',
        'changed' => 'La liste (:noun) a changé pendant votre modification. Rechargez le board et réessayez.',
        'delete_confirm' => 'Supprimer',
        'delete_only' => 'Un board a besoin d\'au moins une colonne (:noun). Ajoutez-en une autre avant de supprimer celle-ci.',
        'delete_last_visible' => 'Une colonne (:noun) reste visible. Affichez-en une autre avant de supprimer celle-ci.',
        'records' => [
            'opportunity' => 'opportunité|opportunités',
            'task' => 'tâche|tâches',
        ],
    ],

    'layout' => [
        'columns' => 'Colonnes',
        'card_size' => 'Taille des cartes',
        'columns_collapsed' => 'Colonnes réduites',
        'columns_expanded' => 'Colonnes développées',
        'compact' => 'Cartes compactes',
        'full' => 'Cartes complètes',
    ],

    'move_denied' => 'Vous n\'avez pas l\'autorisation de déplacer cette carte.',

    'sorted_no_reorder' => 'Les cartes sont triées par :field. Supprimez le tri pour les organiser à la main.',

    'move_to' => [
        'label' => 'Déplacer vers',
        'moved' => 'Déplacé vers :column',
    ],

    'opportunities' => [
        'title' => 'Opportunités',
        'actions' => [
            'add' => 'Ajouter une opportunité',
            'edit' => 'Modifier',
            'delete' => 'Supprimer',
        ],
        'form' => [
            'name_placeholder' => 'Saisissez le titre de l\'opportunité',
        ],
        'badges' => [
            'today' => 'Clôture aujourd\'hui',
            'tomorrow' => 'Clôture demain',
            'overdue' => ':date (en retard)',
        ],
        'groupings' => [
            'company' => 'Entreprise',
            'no_company' => 'Sans entreprise',
        ],
    ],

    'tasks' => [
        'title' => 'Tâches',
        'actions' => [
            'add' => 'Ajouter une tâche',
            'edit' => 'Modifier',
            'delete' => 'Supprimer',
        ],
        'badges' => [
            'today' => 'Échéance aujourd\'hui',
            'tomorrow' => 'Échéance demain',
            'overdue' => ':date (en retard)',
        ],
        'groupings' => [
            'assignee' => 'Assigné à',
            'unassigned' => 'Non assignée',
        ],
        'checklist_progress' => 'Progression de la liste de contrôle',
        'checklist_progress_tooltip' => '{1} :checked élément sur :total terminé|[2,*] :checked éléments sur :total terminés',
    ],
];
