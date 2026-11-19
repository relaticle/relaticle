<?php

declare(strict_types=1);

return [
    'navigation_label' => 'Demandes d\'accès',
    'tabs' => [
        'aria' => 'Onglets des demandes d\'accès',
        'incoming' => 'Reçues',
        'outgoing' => 'Envoyées',
    ],
    'filters' => [
        'label' => 'Statut',
        'all' => 'Toutes',
    ],
    'statuses' => [
        'pending' => 'En attente',
        'approved' => 'Approuvée',
        'denied' => 'Refusée',
        'cancelled' => 'Annulée',
        'expired' => 'Expirée',
    ],
    'columns' => [
        'requested_by' => 'Demandé par',
        'sent_to' => 'Envoyé à',
        'email' => 'E-mail',
        'access' => 'Accès',
        'requested' => 'Demandé',
    ],
    'search' => [
        'placeholder' => 'Rechercher par nom ou objet…',
    ],
    'request' => [
        'requested_incoming' => 'a demandé l\'accès',
        'requested_outgoing' => 'vous avez demandé l\'accès',
        'unknown_user' => 'Membre inconnu',
        'email_unavailable' => 'L\'e-mail associé n\'est plus disponible.',
    ],
    'empty' => [
        'filtered_heading' => [
            'pending' => 'Aucune demande en attente',
            'approved' => 'Aucune demande approuvée',
            'denied' => 'Aucune demande refusée',
            'cancelled' => 'Aucune demande annulée',
            'expired' => 'Aucune demande expirée',
        ],
        'filtered_description' => 'Essayez un autre filtre ou effacez le filtre actif.',
        'show_all' => 'Tout afficher',
        'incoming_heading' => 'Aucune demande reçue',
        'outgoing_heading' => 'Aucune demande envoyée',
        'incoming_description' => 'Quand quelqu\'un demande l\'accès à l\'un de vos e-mails privés, la demande apparaît ici.',
        'outgoing_description' => "Vous n'avez encore demandé l'accès à aucun e-mail.",
    ],
    'actions' => [
        'open_email' => 'Ouvrir l\'e-mail',
        'approve' => [
            'label' => 'Approuver',
            'modal_heading' => 'Approuver la demande d\'accès',
            'modal_description' => 'Accorder à :name l\'accès à cet e-mail ?',
            'modal_submit_label' => 'Approuver',
        ],
        'deny' => [
            'label' => 'Refuser',
            'modal_heading' => 'Refuser la demande d\'accès',
            'modal_description' => 'Refuser la demande d\'accès de :name ?',
            'modal_submit_label' => 'Refuser',
        ],
        'cancel' => [
            'label' => 'Annuler la demande',
            'modal_heading' => 'Annuler la demande d\'accès',
            'modal_description' => 'Retirer votre demande d\'accès à l\'e-mail de :name ?',
            'modal_submit_label' => 'Annuler la demande',
        ],
        'fallback_user' => 'ce membre',
    ],
    'notifications' => [
        'approved' => 'Demande d\'accès approuvée.',
        'denied' => 'Demande d\'accès refusée.',
        'cancelled' => 'Demande d\'accès annulée.',
        'no_longer_pending' => 'Cette demande n\'est plus en attente.',
    ],
];
