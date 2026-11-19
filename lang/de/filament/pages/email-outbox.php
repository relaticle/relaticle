<?php

declare(strict_types=1);

return [
    'title' => 'Postausgang',
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
        'queued' => 'Eingeplant',
        'sending' => 'Wird gesendet',
        'completed' => 'Abgeschlossen',
        'partial_failure' => 'Teilweise fehlgeschlagen',
    ],
    'filters' => [
        'status' => 'Status',
        'status_indicator' => 'Status: :status',
    ],
    'tabs' => [
        'queued' => 'Eingeplant',
        'scheduled' => 'Terminiert',
        'sending' => 'Wird gesendet',
        'failed' => 'Fehlgeschlagen',
        'sent' => 'In den letzten 24 Stunden gesendet',
    ],
    'actions' => [
        'reschedule_field' => 'Senden um',
        'bulk_cancel' => 'Ausgewählte abbrechen',
        'cancel' => 'Abbrechen',
        'reschedule' => 'Neu terminieren',
        'retry' => 'Wiederholen',
    ],
    'notifications' => [
        'cancelled' => 'Abgebrochen',
        'rescheduled' => 'Neu terminiert',
        'retry_queued' => 'Neuer Versuch eingeplant',
        'bulk_cancelled' => '{1}1 E-Mail abgebrochen|[2,*]:count E-Mails abgebrochen',
        'bulk_cancelled_with_skipped' => ':cancelled abgebrochen, :skipped übersprungen, weil sie bereits gesendet werden',
    ],
];
