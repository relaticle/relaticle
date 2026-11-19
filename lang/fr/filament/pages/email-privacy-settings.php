<?php

declare(strict_types=1);

return [
    'title' => 'Confidentialité de l\'espace de travail',
    'navigation_label' => 'Confidentialité de l\'espace de travail',
    'tabs' => [
        'aria' => 'Paramètres e-mail de l\'espace de travail',
        'visibility' => 'Visibilité des e-mails',
        'record_creation' => 'Création d\'enregistrements',
    ],
    'actions' => [
        'save' => 'Enregistrer',
    ],
    'visibility' => [
        'heading' => 'Visibilité des e-mails',
        'description' => 'Masquez partout dans Relaticle les e-mails et événements d\'agenda impliquant certaines adresses.',
        'add' => 'Ajouter des adresses',
        'edit_enforcement' => 'Modifier le niveau d\'application',
        'search_placeholder' => 'Rechercher des adresses ou des domaines',
        'empty_heading' => 'Aucune adresse personnalisée pour le moment',
        'empty_hint' => 'Les valeurs système ci-dessus s\'appliquent toujours. Ajoutez des adresses ou domaines personnalisés pour couvrir davantage.',
        'emails_label' => 'Adresses e-mail',
        'emails_placeholder' => 'ex. legal@acme.com',
        'emails_after_label' => 'Appuyez sur Entrée pour ajouter chaque adresse.',
        'domains_label' => 'Domaines',
        'domains_placeholder' => 'ex. acme.com',
        'domains_after_label' => 'Appuyez sur Entrée pour ajouter chaque domaine.',
        'include_subdomains_label' => 'Inclure les sous-domaines',
        'include_subdomains_hint' => 'Masque aussi les e-mails d\'adresses comme user@mail.example.com quand vous ajoutez example.com.',
        'include_subdomains_short' => 'Inclure les sous-domaines',
        'enforcement' => [
            'protected' => [
                'label' => 'Protégé',
                'description' => 'Masqué uniquement quand toutes les adresses de l\'e-mail ou de l\'événement sont protégées ou bloquées.',
            ],
            'blocked' => [
                'label' => 'Bloqué',
                'description' => 'Toujours masqué quand cette adresse figure dans l\'e-mail ou l\'événement.',
            ],
        ],
        'notifications' => [
            'added' => 'Visibilité des e-mails mise à jour.',
            'updated' => 'Niveau d\'application mis à jour.',
            'deleted' => 'Entrée de visibilité supprimée.',
        ],
        'table' => [
            'address' => 'E-mail / Domaine',
            'subdomains' => 'Sous-domaines',
            'enforcement' => 'Niveau d\'application',
            'updated' => 'Dernière mise à jour',
            'added_by' => 'Ajouté par',
            'actions' => 'Actions',
            'members_row' => "Adresses e-mail des membres de l'espace de travail",
            'system_default' => 'Valeur système',
            'unknown_user' => 'Inconnu',
        ],
    ],
    'blocklist' => [
        'heading' => 'Liste de blocage de l\'espace de travail',
        'description' => 'Les e-mails de ces adresses et domaines sont masqués dans toutes les boîtes mail connectées de cet espace de travail.',
        'add' => 'Ajouter à la liste de blocage',
        'empty_heading' => 'Aucune adresse ni aucun domaine pour le moment',
        'empty_description' => 'Les e-mails des domaines et adresses bloqués n\'apparaîtront dans Relaticle pour aucune boîte mail de cet espace de travail.',
        'emails_label' => 'Adresses bloquées',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Appuyez sur Entrée pour ajouter chaque adresse.',
        'domains_label' => 'Domaines bloqués',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Appuyez sur Entrée pour ajouter chaque domaine.',
        'notifications' => [
            'added' => 'Liste de blocage mise à jour.',
            'deleted' => 'Entrée de la liste de blocage supprimée.',
        ],
        'table' => [
            'address' => 'E-mail / Domaine',
            'type' => 'Type',
            'added_by' => 'Ajouté par',
            'actions' => 'Actions',
            'unknown_user' => 'Inconnu',
        ],
    ],
    'record_creation' => [
        'heading' => 'Création automatique d\'enregistrements',
        'description' => 'S\'applique à chaque boîte mail et agenda connectés de l\'espace de travail. Les adresses des domaines e-mail de vos membres ne créent jamais d\'enregistrements. Ce paramètre n\'affecte que les e-mails et événements synchronisés ensuite.',
        'recommended' => 'Recommandé',
        'modes' => [
            'all' => [
                'label' => 'Toutes les personnes',
                'description' => 'Des enregistrements seront créés pour toutes les personnes présentes dans les e-mails et événements d\'agenda de vos membres.',
            ],
            'selective' => [
                'label' => 'Création sélective de personnes',
                'description' => 'Des enregistrements ne seront créés que pour les personnes qui reçoivent des e-mails de vos membres ou figurent dans leurs événements d\'agenda.',
            ],
            'none' => [
                'label' => 'Aucune',
                'description' => 'Aucun enregistrement ne sera créé automatiquement. Les e-mails et événements d\'agenda restent liés aux enregistrements créés manuellement.',
            ],
        ],
        'companies' => [
            'label' => 'Créer automatiquement les enregistrements d\'entreprise',
            'description' => 'Si activé, une entreprise est créée à partir du domaine e-mail d\'une personne. Cela suit le paramètre sur les personnes ci-dessus. Indisponible quand la création d\'enregistrements est sur Aucune.',
        ],
    ],
    'tiers' => [
        'private' => [
            'label' => 'Privé',
            'description' => 'Rien n\'est partagé. Vous seul pouvez voir ces e-mails.',
        ],
        'metadata_only' => [
            'label' => 'Participants uniquement',
            'description' => 'Votre espace de travail voit les participants et l\'horodatage. L\'objet et le message restent privés.',
        ],
        'subject' => [
            'label' => 'Objet et participants',
            'description' => 'Votre espace de travail voit l\'objet, les participants et l\'horodatage. Le message reste privé.',
        ],
        'full' => [
            'label' => 'Accès complet',
            'description' => 'Votre espace de travail voit le corps du message, l\'objet et les pièces jointes.',
        ],
    ],
    'notifications' => [
        'saved' => 'Paramètres de confidentialité enregistrés.',
    ],
];
