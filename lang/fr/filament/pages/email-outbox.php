<?php

declare(strict_types=1);

return [
    'title' => 'Boîte d\'envoi',
    'columns' => [
        'recipients' => 'Destinataires',
        'scheduled_for' => 'Programmé pour',
        'type' => 'Type',
    ],
    'priorities' => [
        'priority' => 'E-mail unique',
        'bulk' => 'Envoi en masse',
    ],
    'batch_statuses' => [
        'queued' => 'En file d\'attente',
        'sending' => 'Envoi en cours',
        'completed' => 'Terminé',
        'partial_failure' => 'Échec partiel',
    ],
    'filters' => [
        'status' => 'Statut',
        'status_indicator' => 'Statut : :status',
    ],
    'tabs' => [
        'queued' => 'En file d\'attente',
        'scheduled' => 'Programmés',
        'sending' => 'Envoi en cours',
        'failed' => 'Échoués',
        'sent' => 'Envoyés ces dernières 24 heures',
    ],
    'actions' => [
        'reschedule_field' => 'Envoyer le',
        'bulk_cancel' => 'Annuler la sélection',
        'cancel' => 'Annuler',
        'reschedule' => 'Reprogrammer',
        'retry' => 'Réessayer',
    ],
    'notifications' => [
        'cancelled' => 'Annulé',
        'rescheduled' => 'Reprogrammé',
        'retry_queued' => 'Nouvelle tentative en file d\'attente',
        'bulk_cancelled' => '{1}1 e-mail annulé|[2,*]:count e-mails annulés',
        'bulk_cancelled_with_skipped' => ':cancelled annulés, :skipped ignorés car déjà en cours d\'envoi',
    ],
];
