<?php

declare(strict_types=1);

return [
    'actions' => [
        'compose' => [
            'label' => 'Verfassen',
            'tooltip' => 'Tastenkürzel: C',
        ],
        'compose_email' => [
            'label' => 'E-Mail verfassen',
        ],
        'undo' => [
            'label' => 'Rückgängig',
        ],
        'cancel_send' => [
            'label' => 'Senden abbrechen',
        ],
    ],
    'notifications' => [
        'queued' => [
            'title' => 'E-Mail eingeplant',
            'body' => 'Ihre E-Mail wird gesendet.',
        ],
        'cancelled' => [
            'title' => 'Senden abgebrochen',
        ],
        'held' => [
            'title' => ':via hat eine E-Mail eingeplant',
            'rescheduled_title' => ':via hat eine E-Mail neu terminiert',
            'body' => '„:subject“ geht in :minutes an :recipients. Arbeitsbereich: :workspace. Brechen Sie das Senden ab, wenn Sie es nicht veranlasst haben.',
            'scheduled_body' => '„:subject“ geht am :date an :recipients. Arbeitsbereich: :workspace. Brechen Sie das Senden ab, wenn Sie es nicht veranlasst haben.',
            'sent_body' => '„:subject“ wurde an :recipients gesendet. Arbeitsbereich: :workspace.',
            'cancelled_body' => '„:subject“ an :recipients wurde abgebrochen und nicht gesendet. Arbeitsbereich: :workspace.',
            'failed_body' => '„:subject“ an :recipients konnte nicht gesendet werden. Arbeitsbereich: :workspace.',
            'sending_body' => '„:subject“ wird an :recipients gesendet. Arbeitsbereich: :workspace.',
            'waiting_body' => '„:subject“ an :recipients wartet auf den Versand. Arbeitsbereich: :workspace. Brechen Sie das Senden ab, wenn Sie es nicht veranlasst haben.',
            'minutes' => ':count Minute|:count Minuten',
        ],
        'too_late' => [
            'title' => 'Zu spät, die E-Mail wurde bereits gesendet',
        ],
        'not_found' => [
            'title' => 'Diese E-Mail wurde nicht gefunden',
        ],
    ],
    'fields' => [
        'template' => [
            'label' => 'Vorlage',
            'placeholder' => 'Vorlage anwenden…',
        ],
        'body' => [
            'label' => 'Inhalt',
        ],
        'scheduled_for' => [
            'label' => 'Senden um',
            'helper_text' => 'Leer lassen, um mit 5 Sekunden Zeit zum Rückgängigmachen zu senden.',
        ],
        'signature' => [
            'label' => 'Signatur',
            'placeholder' => 'Keine Signatur',
        ],
    ],
    'sections' => [
        'settings' => [
            'description' => 'Datenschutz-, Zeitplan- und Signaturoptionen für diese E-Mail.',
        ],
    ],
];
