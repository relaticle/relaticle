<?php

declare(strict_types=1);

return [
    'approved' => [
        'title' => 'Accès à l\'e-mail accordé',
        'body' => ':owner a accepté votre demande.',
        'body_without_owner' => 'Votre demande a été acceptée.',
    ],

    'denied' => [
        'title' => 'Accès à l\'e-mail refusé',
        'body' => ':owner a refusé votre demande.',
        'body_without_owner' => 'Votre demande a été refusée.',
    ],

    'expired' => [
        'title' => 'Demande d\'accès à l\'e-mail expirée',
        'body' => ':owner n\'a pas répondu à temps.',
        'body_without_owner' => 'Votre demande a expiré sans réponse.',
    ],

    'mail' => [
        'subject_line' => 'Objet : :subject',
        'action_view' => 'Voir l\'e-mail',
        'action_open' => 'Ouvrir Relaticle',
    ],
];
