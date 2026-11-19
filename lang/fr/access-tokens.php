<?php

declare(strict_types=1);

return [
    'title' => 'Jetons d\'accès',

    'sections' => [
        'create' => [
            'title' => 'Créer un jeton d\'accès',
            'description' => 'Les jetons d\'accès permettent aux services tiers et aux agents IA de s\'authentifier auprès de notre application en votre nom.',
        ],
        'manage' => [
            'title' => 'Gérer les jetons d\'accès',
            'description' => 'Vous pouvez supprimer vos jetons existants s\'ils ne sont plus nécessaires.',
        ],
    ],

    'form' => [
        'name' => 'Nom du jeton',
        'workspace' => 'Espace de travail',
        'expiration' => 'Expiration',
        'expiration_placeholder' => 'Choisir une expiration...',
        'permissions' => 'Autorisations',
        'token' => 'Jeton',
    ],

    'table' => [
        'columns' => [
            'name' => 'Nom',
            'workspace' => 'Espace de travail',
            'abilities' => 'Autorisations',
            'expires_at' => 'Expire',
            'last_used_at' => 'Dernière utilisation',
            'created_at' => 'Créé',
        ],
        'placeholders' => [
            'no_workspace' => '—',
            'never' => 'Jamais',
        ],
    ],

    'actions' => [
        'create' => 'Créer',
    ],

    'permissions' => [
        'all' => 'Toutes',
        'read' => 'Lire',
        'create' => 'Créer',
        'update' => 'Mettre à jour',
        'delete' => 'Supprimer',
        'email_read' => 'Lire les e-mails',
        'email_draft' => 'Rédiger des brouillons d\'e-mail',
        'email_send' => 'Envoyer des e-mails',
    ],

    'modals' => [
        'show_token' => [
            'title' => 'Jeton d\'accès',
            'description' => 'Veuillez copier votre nouveau jeton d\'accès. Pour votre sécurité, il ne sera plus affiché.',
            'cancel_label' => 'Fermer',
            'copy_to_clipboard_tooltip' => 'Copier dans le presse-papiers',
            'copied_tooltip' => 'Copié !',
        ],
        'permissions' => [
            'title' => 'Autorisations du jeton d\'accès',
            'action_label' => 'Autorisations',
            'tooltip' => 'Modifier les autorisations',
            'heading' => 'Autorisations : :name',
        ],
        'delete' => [
            'title' => 'Supprimer le jeton d\'accès',
            'description' => 'Voulez-vous vraiment supprimer ce jeton d\'accès ?',
        ],
    ],

    'expirations' => [
        '1' => '1 jour',
        '7' => '7 jours',
        '30' => '30 jours',
        '60' => '60 jours',
        '90' => '90 jours',
        '180' => '180 jours',
        '365' => '1 an',
        '0' => 'Sans expiration',
    ],

    'notifications' => [
        'workspace_not_member' => 'Vous n\'appartenez pas à cet espace de travail.',
        'permissions_updated' => 'Autorisations du jeton d\'accès mises à jour.',
        'deleted' => 'Jeton d\'accès supprimé.',
    ],

    'empty_state' => [
        'heading' => 'Aucun jeton d\'accès',
        'description' => 'Créez un jeton ci-dessus pour commencer.',
    ],

    'connectors' => [
        'title' => 'Connecteurs IA',
        'description' => 'Les assistants et applications, comme Claude, ChatGPT et Maxforms, que vous avez connectés via l\'écran de consentement. En révoquer un invalide immédiatement son accès.',
        'columns' => [
            'name' => 'Connecteur',
            'workspace' => 'Espace de travail',
            'active_tokens' => 'Jetons actifs',
        ],
        'actions' => [
            'revoke' => 'Révoquer',
        ],
        'modals' => [
            'revoke' => [
                'title' => 'Révoquer le connecteur',
                'description' => 'L\'assistant perdra immédiatement l\'accès à cet espace de travail. Vous pouvez le reconnecter à tout moment.',
            ],
        ],
        'notifications' => [
            'revoked' => 'Connecteur révoqué.',
        ],
        'empty_state' => [
            'heading' => 'Aucun connecteur IA',
            'description' => 'Connectez Relaticle depuis Claude ou ChatGPT et le connecteur apparaîtra ici.',
        ],
    ],

    'integrations' => [
        'heading' => 'Et ensuite',
        'api_link' => 'API REST',
        'api_description' => 'Gérez les données du CRM par programmation.',
        'mcp_link' => 'MCP server',
        'mcp_description' => 'Connectez des assistants IA comme Claude.',
        'forms_link' => 'Formulaires web',
        'forms_description' => 'Transformez les réponses aux formulaires en enregistrements avec Maxforms.',
    ],

    'user_menu' => 'Jetons d\'accès',
];
