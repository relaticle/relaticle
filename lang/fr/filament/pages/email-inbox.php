<?php

declare(strict_types=1);

return [
    'views' => [
        'needs_reply' => 'À répondre',
        'awaiting_reply' => 'En attente de réponse',
        'all' => 'Toutes',
        'unlinked' => 'Non liées',
        'automated' => 'Automatiques',
        'done' => 'Terminées',
        'newer' => 'Plus récentes',
        'older' => 'Plus anciennes',
        'empty' => [
            'needs_reply' => 'Rien ne vous attend.',
            'awaiting_reply' => 'Personne ne vous doit de réponse.',
            'all' => 'Aucune conversation pour le moment.',
            'unlinked' => 'Chaque conversation est liée à une fiche.',
            'automated' => 'Aucun e-mail automatique.',
            'done' => 'Les conversations marquées comme terminées apparaissent ici. Elles reviennent quand quelqu\'un écrit à nouveau.',
        ],
    ],
    'filters' => [
        'on_or_before' => 'au plus tard le :date',
    ],
    'conversation_row' => [
        'me' => 'Moi',
        'unread' => 'Non lu',
        'unlinked' => 'Non liée',
        'more_records' => '+:count',
    ],
    'triage' => [
        'too_many' => 'Sélectionnez au plus :max conversations à la fois.',
        'skipped' => [
            'not_available' => 'non disponible',
            'not_in_your_mailbox' => 'absente de votre boîte mail',
        ],
        'done_badge' => 'Terminée',
        'actions' => [
            'done' => 'Terminer',
            'reopen' => 'Rouvrir',
            'mark_read' => 'Marquer comme lue',
            'mark_unread' => 'Marquer comme non lue',
        ],
        'toast' => [
            'markDone' => 'Conversation terminée dans Relaticle.|:count conversations terminées dans Relaticle.',
            'reopen' => 'Conversation rouverte.|:count conversations rouvertes.',
            'markRead' => 'Conversation marquée comme lue.|:count conversations marquées comme lues.',
            'markUnread' => 'Conversation marquée comme non lue.|:count conversations marquées comme non lues.',
            'skipped' => ':count ignorée(s) : :reason.',
            'undo' => 'Annuler',
            'undo_hint' => 'Annulation possible pendant 5 secondes.',
            'dismiss' => 'Fermer',
        ],
    ],
    'shortcuts' => [
        'labels' => [
            'next' => 'Conversation suivante',
            'previous' => 'Conversation précédente',
            'open' => 'Ouvrir la conversation',
            'close' => 'Fermer',
            'reply' => 'Répondre',
            'reply_all' => 'Répondre à tous',
            'forward' => 'Transférer',
            'done' => 'Terminer ou rouvrir',
            'mark_unread' => 'Marquer comme non lue',
            'focus_search' => 'Rechercher',
            'compose' => 'Nouvel e-mail',
            'help' => 'Raccourcis clavier',
            'undo' => 'Annuler',
        ],
        'scopes' => [
            'list' => 'Liste des conversations',
            'reader' => 'Lecture d\'une conversation',
            'composer' => 'Rédaction d\'un e-mail',
            'global' => 'Partout',
        ],
    ],
    'drawer' => [
        'reply' => 'Répondre',
        'source' => 'Visible via la boîte mail de :name',
        'previous' => 'Conversation précédente',
        'next' => 'Conversation suivante',
        'resize' => 'Redimensionner le lecteur',
        'resize_value' => ':width %',
        'not_available' => 'Cet e-mail ne vous est pas accessible.',
        'gate' => [
            'metadata_only' => [
                'heading' => 'Vous voyez qui et quand',
                'description' => ':name ne partage que l\'auteur et la date de cet e-mail.',
            ],
            'subject' => [
                'heading' => 'Vous voyez l\'objet',
                'description' => ':name ne partage que l\'objet de cet e-mail.',
            ],
            'request' => 'Demander l\'accès',
            'requested' => 'Demandé',
        ],
    ],
];
