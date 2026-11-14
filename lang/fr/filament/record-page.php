<?php

declare(strict_types=1);

return [
    'sections' => [
        'details' => 'Détails',
        'record_info' => 'Informations',
    ],
    'fields' => [
        'created_by' => 'Créé par',
        'created_at' => 'Créé le',
        'updated_at' => 'Dernière mise à jour',
    ],
    'actions' => [
        'copy_page_url' => "Copier l'URL de la page",
        'copy_record_id' => "Copier l'identifiant",
        'merge_record' => 'Fusionner la fiche',
        'new_note' => 'Nouvelle note',
        'new_task' => 'Nouvelle tâche',
        'open_preview' => "Ouvrir l'aperçu",
        'open_record' => 'Ouvrir la fiche',
    ],
    'preview' => [
        'previous' => 'Fiche précédente',
        'next' => 'Fiche suivante',
        'position' => ':position sur :total dans :label',
    ],
    'overview' => [
        'label' => 'Aperçu',
        'highlights' => 'Points clés',
        'activity' => 'Activité',
        'view_all' => 'Tout voir',
        'notes' => 'Notes',
        'tasks' => 'Tâches',
        'emails' => 'E-mails',
        'next_task' => 'Prochaine tâche',
        'no_next_task' => 'Aucune tâche ouverte',
        'today' => "Aujourd'hui",
        'tomorrow' => 'Demain',
        'last_interaction' => 'Dernière interaction',
        'no_interaction' => 'Aucune interaction',
    ],
    'notifications' => [
        'url_copied' => 'URL copiée dans le presse-papiers',
        'id_copied' => "ID de l'enregistrement copié dans le presse-papiers",
        'records_merged' => 'Fiches fusionnées',
    ],
    'merge' => [
        'heading' => 'Fusionner',
        'notice' => "La fiche de droite l'emporte lorsque les deux fiches contiennent une valeur.",
        'search' => 'Rechercher',
        'swap' => 'Inverser les fiches',
        'submit' => 'Fusionner les fiches',
        'unavailable' => "Cette fiche n'est plus disponible pour la fusion.",
        'over_limit' => ":field peut contenir :max valeurs. Ensemble, ces fiches en ont davantage. Supprimez-en d'abord.",
        'pick' => 'Choisir une fiche',
        'clear' => 'Choisir une autre fiche',
        'search_placeholder' => [
            'company' => 'Rechercher par nom ou domaine',
            'people' => 'Rechercher par nom ou e-mail',
        ],
        'no_results' => 'Aucune fiche trouvée',
        'no_domains' => 'Aucun domaine',
        'no_emails' => 'Aucun e-mail',
        'record' => 'fiche',
        'keys' => [
            'escape' => 'Échap',
            'enter' => '↵',
        ],
        'counts' => [
            'people' => '{0} Aucune personne liée|{1} :count personne|[2,*] :count personnes',
            'opportunities' => '{0} Aucune opportunité liée|{1} :count opportunité|[2,*] :count opportunités',
            'contact_opportunities' => '{0} Aucune opportunité comme point de contact|{1} :count opportunité comme point de contact|[2,*] :count opportunités comme point de contact',
            'notes' => '{0} Aucune note liée|{1} :count note|[2,*] :count notes',
            'tasks' => '{0} Aucune tâche liée|{1} :count tâche|[2,*] :count tâches',
        ],
    ],
    'empty' => '—',
    'view_all' => 'Tout afficher',
    'show_less' => 'Afficher moins',
    'resize_details' => 'Redimensionner le panneau des détails',
];
