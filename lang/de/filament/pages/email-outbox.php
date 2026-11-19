<?php

declare(strict_types=1);

return [
    'title' => 'Posteingang',
    'columns' => [
        'recipients' => 'Empfänger',
        'scheduled_for' => 'Geplant für',
        'type' => 'Typ',
    ],
    'priorities' => [
        'priority' => 'Einzelne E-Mail',
        'bulk' => 'Massenversand',
    ],
    'batch_statuses' => [
        'queued' => 'In der Warteschlange',
        'sending' => 'Wird gesendet',
        'completed' => 'Abgeschlossen',
        'partial_failure' => 'Teilweise fehlgeschlagen',
    ],
    'filters' => [
        'status' => 'Status',
        'status_indicator' => 'Status: :status',
    ],
    'tabs' => [
        'queued' => 'In Warteschlange',
        'scheduled' => 'Geplant',
        'sending' => 'Wird gesendet',
        'failed' => 'Fehlgeschlagen',
        'sent' => 'Gesendet in den letzten 24 Stunden',
    ],
    'actions' => [
        'reschedule_field' => 'Senden am',
        'bulk_cancel' => 'Ausgewählte abbrechen',
    ],
    'notifications' => [
        'cancelled' => 'Abgebrochen',
        'rescheduled' => 'Neu geplant',
        'retry_queued' => 'Wiederholung in die Warteschlange gestellt',
        'bulk_cancelled' => '{1}Abgebrochen 1 E-Mail|[2,*]Abgebrochen :count E-Mails',
        'bulk_cancelled_with_skipped' => 'Abgebrochen :cancelled, übersprungen :skipped bereits sendend',
    ],
];
