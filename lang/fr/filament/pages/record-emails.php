<?php

declare(strict_types=1);

return [
    'actions' => [
        'manage_sharing' => [
            'label' => 'Partage',
            'modal_heading' => 'Paramètres de partage',
            'submit' => 'Enregistrer',
        ],
        'summarize_thread' => [
            'label' => 'Résumer la conversation',
            'modal_heading' => 'Résumé IA de la conversation',
            'empty' => 'Aucun résumé n\'est disponible pour cette conversation.',
            'failed' => 'Le fournisseur d\'IA n\'a pas pu générer de résumé. Réessayez plus tard.',
            'generated' => 'Généré :time',
            'copy' => 'Copier',
            'copied' => 'Copié',
        ],
        'request_access' => [
            'label' => 'Demander l\'accès',
            'modal_heading' => 'Demander l\'accès',
        ],
        'approve_access_request' => [
            'modal_heading' => 'Approuver la demande d\'accès',
        ],
        'deny_access_request' => [
            'modal_heading' => 'Refuser la demande d\'accès',
        ],
    ],
    'fields' => [
        'privacy_tier' => [
            'label' => 'Qui peut voir cet e-mail ?',
        ],
        'shares' => [
            'label' => 'Partager avec des membres précis',
        ],
        'shared_with' => [
            'label' => 'Membre',
        ],
        'tier' => [
            'label' => 'Niveau d\'accès',
        ],
        'tier_requested' => [
            'label' => 'Niveau d\'accès demandé',
        ],
    ],
    'empty' => [
        'heading' => 'Aucun e-mail',
        'description' => 'Cet enregistrement n\'a aucun e-mail, ou ils sont masqués par les autorisations.',
        'compose' => 'Rédiger',
    ],
    'protected' => [
        'heading' => 'Rien à afficher ici',
        'description' => 'Cet enregistrement est protégé. Ses e-mails et réunions restent masqués ici.',
    ],
    'blocked' => [
        'heading' => 'Rien à afficher ici',
        'description' => 'Cet enregistrement est bloqué. Ses e-mails et réunions restent masqués ici.',
    ],

    'notifications' => [
        'sharing_saved' => [
            'title' => 'Paramètres de partage enregistrés.',
        ],
        'pending_request' => [
            'title' => 'Vous avez déjà une demande en attente pour cet e-mail.',
        ],
        'access_request_sent' => [
            'title' => 'Demande d\'accès envoyée.',
        ],
        'access_request_approved' => [
            'title' => 'Demande d\'accès approuvée.',
        ],
        'access_request_denied' => [
            'title' => 'Demande d\'accès refusée.',
        ],
    ],
];
