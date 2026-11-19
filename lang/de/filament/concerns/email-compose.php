<?php

declare(strict_types=1);

return [
    'actions' => [
        'compose' => [
            'label' => 'Verfassen',
            'tooltip' => 'Tastaturkürzel: C',
        ],
        'compose_email' => [
            'label' => 'E-Mail verfassen',
        ],
        'undo' => [
            'label' => 'Rückgängig machen',
        ],
        'cancel_send' => [
            'label' => 'Senden abbrechen',
        ],
    ],
    'notifications' => [
        'queued' => [
            'title' => 'E-Mail in die Warteschlange gestellt',
            'body' => 'Ihre E-Mail wird gesendet.',
        ],
        'cancelled' => [
            'title' => 'Senden abgebrochen',
        ],
        'held' => [
            'title' => ':via hat eine E-Mail in die Warteschlange gestellt',
            'rescheduled_title' => ':via hat eine E-Mail neu geplant',
            'body' => '":subject" geht an :recipients in :minutes. Arbeitsbereich: :workspace. Brechen Sie es ab, wenn Sie es nicht angefordert haben.',
            'scheduled_body' => '":subject" geht an :recipients am :date. Arbeitsbereich: :workspace. Brechen Sie es ab, wenn Sie es nicht angefordert haben.',
            'sent_body' => '":subject" wurde an :recipients gesendet. Arbeitsbereich: :workspace.',
            'cancelled_body' => '":subject" an :recipients wurde abgebrochen und nicht gesendet. Arbeitsbereich: :workspace.',
            'failed_body' => '":subject" an :recipients konnte nicht gesendet werden. Arbeitsbereich: :workspace.',
            'sending_body' => '":subject" wird an :recipients gesendet. Arbeitsbereich: :workspace.',
            'waiting_body' => '":subject" an :recipients wartet auf den Versand. Arbeitsbereich: :workspace. Brechen Sie es ab, wenn Sie es nicht angefordert haben.',
            'minutes' => ':count Minute|:count Minuten',
        ],
        'too_late' => [
            'title' => 'Zu spät, die E-Mail wurde bereits gesendet',
        ],
        'not_found' => [
            'title' => 'Diese E-Mail konnte nicht gefunden werden',
        ],
    ],
    'fields' => [
        'template' => [
            'label' => 'Vorlage',
            'placeholder' => 'Vorlage anwenden...',
        ],
        'body' => [
            'label' => 'Inhalt',
        ],
        'scheduled_for' => [
            'label' => 'Senden am',
            'helper_text' => 'Lassen Sie es leer, um mit einem 5-Sekunden-Rückgängig-Zeitfenster zu senden.',
        ],
        'signature' => [
            'label' => 'Signatur',
            'placeholder' => 'Keine Signatur',
        ],
    ],
    'sections' => [
        'settings' => [
            'description' => 'Datenschutz-, Planungs- und Signaturoptionen für diese E-Mail.',
        ],
    ],
];
