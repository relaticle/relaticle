<?php

declare(strict_types=1);

return [
    'activation' => [
        'heading' => 'Premiers pas',
        'progress' => ':completed/:total étapes terminées',
        'dismiss' => 'Ignorer',
        'collapse' => 'Réduire la liste',
        'more_actions' => 'Plus d\'actions',
        'encouragement' => "C'est parti !",
        'invite_members' => 'Inviter des membres',
        'sample_data' => 'Cet espace de travail contient des enregistrements d\'exemple pour que vous puissiez explorer. Ce que vous ajoutez s\'affiche à côté.',
        'remove_sample_data' => 'Supprimer les données d\'exemple',
        'remove_sample_data_confirm' => 'Supprimer tous les enregistrements d\'exemple ? Vos propres enregistrements restent.',
        'steps' => [
            'first_record' => [
                'label' => 'Ajoutez votre première personne',
                'description' => 'Ajoutez une vraie personne au CRM, le reste suit',
            ],
            'sync_email' => [
                'label' => 'Synchronisez votre boîte mail',
                'description' => 'Connectez votre boîte mail pour importer conversations et réunions dans le CRM',
                'syncing' => 'Synchronisation des e-mails…',
                'syncing_percent' => 'Synchronisation des e-mails (:percent %)',
            ],
            'import' => [
                'label' => 'Importez vos enregistrements existants',
                'description' => 'Apportez un CSV de votre tableur ou de votre ancien CRM',
            ],
            'invite' => [
                'label' => 'Invitez un membre',
                'description' => 'Un pipeline partagé vaut mieux qu\'un pipeline privé',
            ],
            'ask_rela' => [
                'label' => 'Interrogez Rela sur votre pipeline',
                'label_empty' => 'Demandez à Rela comment démarrer',
                'description' => 'Votre assistant peut lire, rédiger et mettre à jour des enregistrements pour vous',
                'prompt' => 'Que contient mon pipeline en ce moment ?',
                'prompt_empty' => 'Que pouvez-vous faire pour m\'aider à configurer cet espace de travail ?',
            ],
        ],
    ],
    'meetings' => [
        'heading' => 'Réunions',
        'empty' => [
            'title' => 'Aucune réunion',
            'description' => 'Choisissez une autre date pour planifier ou revoir des réunions passées.',
            'next_with_meetings' => 'Prochain jour avec des réunions',
        ],
        'disconnected' => [
            'title' => 'Transformez vos réunions en opportunités',
            'description' => 'Synchronisez votre agenda pour obtenir le contexte de chaque réunion',
        ],
        'date' => [
            'today' => 'Aujourd\'hui,',
            'tomorrow' => 'Demain,',
            'yesterday' => 'Hier,',
            'other' => ':weekday,',
        ],
        'previous_day' => 'Jour précédent',
        'next_day' => 'Jour suivant',
        'pick_date' => 'Changer la date, actuellement :date',
        'more_actions' => 'Plus d\'actions sur la réunion',
        'go_to_today' => 'Aller à aujourd\'hui',
        'calendar_settings' => 'Paramètres de l\'agenda',
        'open' => 'Ouvrir la réunion',
        'open_named' => 'Ouvrir :title',
        'expand_participants' => 'Afficher les participants de :title',
        'collapse_participants' => 'Masquer les participants de :title',
        'all_day' => 'Toute la journée',
        'more_participants' => '+:count',
        'happening_now' => 'En cours',
        'time_range' => ':start à :end',
        'load_more' => 'Charger plus',
        'syncing' => [
            'title' => 'Synchronisation',
            'title_with_percent' => 'Synchronisation (:percent %)',
            'description_initial' => 'Nous traitons vos e-mails et événements d\'agenda…',
            'description_update' => 'Recherche de nouveaux e-mails et de mises à jour de l\'agenda…',
            'emails_processed' => '{1}:count e-mail synchronisé|[2,*]:count e-mails synchronisés',
            'meetings_processed' => '{1}:count réunion synchronisée|[2,*]:count réunions synchronisées',
            'emails_updated' => '{1}:count nouvel e-mail|[2,*]:count nouveaux e-mails',
            'meetings_updated' => '{1}:count réunion mise à jour|[2,*]:count réunions mises à jour',
        ],
    ],
    'tasks' => [
        'heading' => 'Tâches',
        'view_all' => 'Tout voir',
        'create_action_label' => 'Nouvelle tâche',
        'complete' => 'Marquer comme terminée',
        'empty' => [
            'title' => 'Gardez le contrôle de votre travail',
            'description' => 'Créez des tâches pour vous ou votre équipe afin de suivre les prochaines étapes',
        ],
    ],
];
