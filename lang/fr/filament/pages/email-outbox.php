<?php

declare(strict_types=1);

return [
    'title' => 'Boîte d\'envoi',
    'columns' => [
        'recipients' => 'Destinataires',
        'scheduled_for' => 'Planifié pour',
        'type' => 'Type',
    ],
    'priorities' => [
        'priority' => 'E-mail unique',
        'bulk' => 'Envoi en masse',
    ],
    'batch_statuses' => [
        'queued' => 'En attente',
        'sending' => 'Envoi en cours',
        'completed' => 'Terminé',
        'partial_failure' => 'Échec partiel',
    ],
    'filters' => [
        'status' => 'Statut',
        'status_indicator' => 'Statut : :status',
    ],
    'tabs' => [
        'queued' => 'En attente',
        'scheduled' => 'Planifiés',
        'sending' => 'Envoi en cours',
        'failed' => 'Échoués',
        'sent' => 'Envoyés ces dernières 24 heures',
    ],
    'actions' => [
        'reschedule_field' => 'Envoyer le',
        'bulk_cancel' => 'Annuler la sélection',
    ],
    'notifications' => [
        'cancelled' => 'Annulé',
        'rescheduled' => 'Replanifié',
        'retry_queued' => 'Nouvelle tentative en attente',
        'bulk_cancelled' => '{1}1 e-mail annulé|[2,*]:count e-mails annulés',
        'bulk_cancelled_with_skipped' => ':cancelled annulés, :skipped ignorés car déjà en cours d\'envoi',
    ],
];
