<?php

declare(strict_types=1);

return [
    'subheading' => 'Mettez à jour les autorisations et les paramètres de votre boîte mail.',
    'tabs' => [
        'general' => 'Général',
        'blocklist' => 'Liste de blocage de cette boîte mail',
        'signatures' => 'Signatures',
    ],
    'general' => [
        'sync_heading' => 'Synchronisation et envoi',
    ],
    'sharing' => [
        'label' => 'Partage des e-mails',
        'hint' => 'Ce que votre espace de travail voit de cette boîte mail. Les autres boîtes mail gardent leur propre niveau.',
    ],
    'blocklist' => [
        'label' => 'Adresses et domaines bloqués',
        'emails_label' => 'Adresses bloquées',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Appuyez sur Entrée pour ajouter chaque adresse.',
        'domains_label' => 'Domaines bloqués',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Appuyez sur Entrée pour ajouter chaque domaine.',
        'include_subdomains_label' => 'Inclure les sous-domaines',
        'include_subdomains_hint' => 'Masque aussi les e-mails d\'adresses comme user@mail.example.com quand vous bloquez example.com.',
        'include_subdomains_column' => 'Sous-domaines',
        'include_subdomains_short' => 'Inclure les sous-domaines',
        'hint' => 'Masqué pour cette boîte mail uniquement. Les autres boîtes mail connectées ne sont pas concernées.',
        'add' => 'Ajouter à la liste de blocage',
        'empty_heading' => 'Aucune adresse ni aucun domaine pour le moment',
        'empty_description' => 'Les e-mails des domaines et adresses bloqués n\'apparaîtront pas dans cette boîte mail.',
        'notifications' => [
            'added' => 'Liste de blocage mise à jour.',
            'deleted' => 'Entrée de la liste de blocage supprimée.',
        ],
    ],
    'signatures' => [
        'label' => 'Signatures',
        'hint' => 'Utilisées lors de la rédaction depuis cette boîte mail.',
        'add' => 'Ajouter une signature',
        'empty_heading' => 'Aucune signature pour le moment',
        'empty_description' => 'Créez une signature à ajouter aux e-mails que vous envoyez depuis cette boîte mail.',
    ],
    'actions' => [
        'save' => 'Enregistrer les modifications',
    ],
    'notifications' => [
        'saved' => 'Paramètres de la boîte mail enregistrés.',
    ],
];
