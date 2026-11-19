<?php

declare(strict_types=1);

return [
    'connection' => [
        'fallback_name' => 'Un assistant IA',
    ],

    'consent' => [
        'title' => 'Autoriser :client',
        'intro' => [
            'one' => ':client demande à se connecter à :workspace.',
            'choose' => ':client demande à se connecter à l\'un de vos espaces de travail.',
        ],
        'redirect' => 'Après votre autorisation, vous retournerez sur :host. Continuez uniquement si vous faites confiance à ce site.',
        'signed_in_as' => 'Connecté en tant que :email.',
        'invalid_resource' => 'Cette connexion a demandé l\'accès à un autre serveur. Relaticle n\'autorise que les connexions à son propre MCP server.',

        'workspace' => [
            'heading' => 'Quel espace de travail ?',
            'description' => 'Pour en utiliser un autre plus tard, révoquez ce connecteur et ajoutez-le de nouveau.',
            'aria_label' => 'Choix de l\'espace de travail',
            'personal' => 'Personnel',
            'paused' => 'En pause. Abonnez-vous pour vous connecter',
            'all_paused' => 'Tous les espaces de travail de ce compte sont en pause. Abonnez-vous à une offre Cloud avant de vous connecter. Un connecteur autorisé sur un espace de travail en pause ne peut lire ni écrire aucune donnée.',
            'none' => [
                'heading' => 'Vous n\'appartenez à aucun espace de travail.',
                'description' => 'Créez ou rejoignez un espace de travail dans Relaticle avant d\'autoriser ce connecteur.',
            ],
        ],

        'permissions' => [
            'heading' => ':client pourra',
            'read' => 'Lire et rechercher vos enregistrements',
            'write' => 'Les créer et les modifier',
            'delete' => 'Les supprimer. Vous pouvez restaurer les enregistrements supprimés.',
            'email_read' => 'Lire les e-mails que vous pouvez voir dans cet espace de travail',
            'email_draft' => 'Enregistrer des brouillons d\'e-mail que vous pourrez relire',
            'email_send' => 'Envoyer des e-mails en votre nom, après un délai que vous pouvez annuler',
            'none' => 'Votre rôle dans cet espace de travail n\'autorise rien de ce que :client a demandé.',
            'excluded' => 'Il ne peut pas accéder à vos autres espaces de travail, aux membres de l\'espace de travail, à la facturation ni aux paramètres du compte.',
        ],

        'actions' => [
            'cancel' => 'Annuler',
            'authorize' => 'Autoriser',
            'authorizing' => 'Autorisation en cours...',
        ],

        'revoke_hint' => 'Révoquez ce connecteur à tout moment depuis la page Jetons d\'accès.',
    ],
];
