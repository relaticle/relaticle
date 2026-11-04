<?php

declare(strict_types=1);

return [
    'views' => [
        'needs_reply' => 'À répondre',
        'awaiting_reply' => 'En attente de réponse',
        'all' => 'Toutes',
        'unlinked' => 'Non liées',
        'automated' => 'Automatiques',
        'newer' => 'Plus récentes',
        'older' => 'Plus anciennes',
        'empty' => [
            'needs_reply' => 'Rien ne vous attend.',
            'awaiting_reply' => 'Personne ne vous doit de réponse.',
            'all' => 'Aucune conversation pour le moment.',
            'unlinked' => 'Chaque conversation est liée à une fiche.',
            'automated' => 'Aucun e-mail automatique.',
        ],
    ],
    'conversation_row' => [
        'me' => 'Moi',
        'unread' => 'Non lu',
        'unlinked' => 'Non liée',
        'more_records' => '+:count',
    ],
    'drawer' => [
        'reply' => 'Répondre',
        'source' => 'Visible via la boîte mail de :name',
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
