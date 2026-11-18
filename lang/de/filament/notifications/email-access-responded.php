<?php

declare(strict_types=1);

return [
    'approved' => [
        'title' => 'E-Mail-Zugriff genehmigt',
        'body' => ':owner hat Ihre Anfrage genehmigt.',
        'body_without_owner' => 'Ihre Anfrage wurde genehmigt.',
    ],

    'denied' => [
        'title' => 'E-Mail-Zugriff abgelehnt',
        'body' => ':owner hat Ihre Anfrage abgelehnt.',
        'body_without_owner' => 'Ihre Anfrage wurde abgelehnt.',
    ],

    'expired' => [
        'title' => 'Anfrage für E-Mail-Zugriff abgelaufen',
        'body' => ':owner hat nicht rechtzeitig geantwortet.',
        'body_without_owner' => 'Ihre Anfrage ist ohne Antwort abgelaufen.',
    ],

    'mail' => [
        'subject_line' => 'Betreff: :subject',
        'action_view' => 'E-Mail ansehen',
        'action_open' => 'Relaticle öffnen',
    ],
];
