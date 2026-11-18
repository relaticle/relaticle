<?php

declare(strict_types=1);

return [
    'title' => 'Notifications',

    'throttled' => [
        'title' => 'Trop de requêtes',
        'body' => 'Veuillez patienter :seconds secondes avant de réessayer.',
    ],

    'digest' => [
        'heading' => 'Résumé quotidien',
        'title' => 'Activer le résumé quotidien',
        'description' => 'Inclut les tâches en retard et à échéance aujourd\'hui. Chaque espace de travail envoie son propre e-mail chaque matin lorsqu\'il a des tâches à échéance ou en retard.',
    ],

    'collaboration' => [
        'heading' => 'Notifications de collaboration',
        'notify_me_about' => 'Me notifier pour',
    ],

    'channels' => [
        'in_app' => 'Application',
        'email' => 'E-mail',
    ],

    'types' => [
        'task_assigned' => [
            'label' => 'Assignations de tâches',
            'description' => 'Me notifier quand une tâche m\'est assignée.',
        ],
        'email_access' => [
            'label' => 'Accès aux e-mails',
            'description' => 'M\'envoyer un e-mail quand quelqu\'un demande à lire un e-mail qui m\'appartient. Me notifier quand ma propre demande est approuvée, refusée ou expire.',
        ],
        'email_shared' => [
            'label' => 'E-mails partagés',
            'description' => 'Me notifier quand quelqu\'un partage un e-mail avec moi.',
        ],
        'task_digest' => [
            'label' => 'Résumé quotidien',
            'description' => 'Me notifier chaque matin des tâches en retard et à échéance aujourd\'hui.',
        ],
    ],

    'events' => [
        'task_assigned' => [
            'title' => ':actor vous a assigné une tâche',
            'title_without_actor' => 'Vous avez une nouvelle tâche',
            'action' => 'Voir la tâche',
        ],
    ],

    'panel' => [
        'heading' => 'Boîte de réception',
        'open' => 'Ouvrir la boîte de réception',
        'open_with_count' => 'Ouvrir la boîte de réception, :count élément requiert votre attention|Ouvrir la boîte de réception, :count éléments requièrent votre attention',
        'close' => 'Fermer la boîte de réception',
        'settings' => 'Paramètres de notification',
        'announce' => ':count nouvelle notification|:count nouvelles notifications',
        'announcements' => [
            'archived' => 'Archivé : :title',
            'approved' => 'Approuvé : :title',
            'denied' => 'Refusé : :title',
            'already_decided' => 'Cette demande a déjà été traitée : :title',
        ],
        'footer' => 'Affichage des :count dernières notifications.',
        'tabs' => [
            'label' => 'Sections de la boîte de réception',
            'notifications' => 'Notifications',
            'requests' => 'Demandes',
        ],
        'mark_all_read' => 'Tout marquer comme lu',
        'groups' => [
            'today' => 'Aujourd\'hui',
            'yesterday' => 'Hier',
            'earlier' => 'Plus tôt',
        ],
        'row' => [
            'unread' => 'Non lu',
            'mark_read' => 'Marquer comme lu',
            'mark_unread' => 'Marquer comme non lu',
            'archive' => 'Archiver',
        ],
        'requests' => [
            'view' => 'Voir',
            'approve' => 'Approuver',
            'deny' => 'Refuser',
            'approved' => 'Approuvée',
            'denied' => 'Refusée',
            'already_decided' => 'Cette demande a déjà été traitée.',
            'expires' => 'Expire :when',
        ],
        'empty' => [
            'notifications' => [
                'heading' => 'Vous êtes à jour',
                'description' => 'Les assignations de tâches, les imports et exports, et les autres mises à jour de cet espace de travail arrivent ici.',
            ],
            'requests' => [
                'heading' => 'Aucune demande en attente',
                'description' => 'Quand un membre demande quelque chose qui vous appartient, vous décidez ici.',
            ],
            'settings' => 'Paramètres',
        ],
    ],

    'saved' => 'Préférences de notification mises à jour.',
];
