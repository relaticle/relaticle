<?php

declare(strict_types=1);

return [
    'navigation_label' => 'E-Mails',
    'account_filter' => [
        'label' => 'Konto',
    ],
    'tabs' => [
        'drafts' => 'Entwürfe',
        'outbox' => 'Posteingang',
        'templates' => 'Vorlagen',
    ],
    'drafts' => [
        'columns' => [
            'subject' => 'Entwurf',
            'last_edited' => 'Zuletzt bearbeitet',
        ],
        'actions' => [
            'open' => 'Weiter schreiben',
            'delete' => 'Entwurf löschen',
            'delete_selected' => 'Entwürfe löschen',
        ],
        'empty' => [
            'heading' => 'Keine Entwürfe',
            'description' => 'Nachrichten, die Sie vor dem Senden schließen, werden hier gespeichert.',
        ],
        'notifications' => [
            'deleted' => 'Entwurf gelöscht',
            'bulk_deleted' => '{1}1 Entwurf gelöscht|[2,*]:count Entwürfe gelöscht',
        ],
    ],
    'outbox' => [
        'empty' => [
            'heading' => 'Keine E-Mails im Posteingang',
            'description' => 'Geplante E-Mails erscheinen hier, bis sie gesendet werden.',
        ],
    ],
    'search' => [
        'placeholder' => 'E-Mails suchen…',
        'clear' => 'Suche löschen',
    ],
    'subject' => [
        'none' => '(kein Betreff)',
        'hidden' => '(Betreff verborgen)',
    ],
    'pagination' => [
        'previous' => 'Vorherige',
        'next' => 'Nächste',
        'range' => ':first–:last von :total',
    ],
    'list_empty' => [
        'no_results' => 'Keine Ergebnisse für \":search\"',
        'all' => 'Keine E-Mails',
        'sent' => 'Keine gesendeten E-Mails',
        'inbox' => 'Keine empfangenen E-Mails',
    ],
    'list_row' => [
        'via' => 'via :name',
        'via_mailboxes' => '{1}via 1 Mailbox|[2,*]via :count Mailboxen',
        'access_granted_via' => 'Zugriff gewährt über',
        'timestamp_yesterday' => 'Gestern, :time',
        'request_access' => 'Zugriff von :name anfordern',
        'requested' => 'Angefordert',
        'opening' => 'Öffnen…',
    ],
    'pending_access' => [
        'heading' => '{1}1 ausstehende Zugriffsanfrage|[2,*]:count ausstehende Zugriffsanfragen',
        'unknown_user' => 'Unbekannter Benutzer',
        'approve' => 'Genehmigen',
        'deny' => 'Ablehnen',
    ],
    'compose' => [
        'label' => 'Verfassen',
        'notifications' => [
            'queued' => [
                'title' => 'E-Mail in die Warteschlange gestellt',
                'body' => 'Ihre E-Mail wird gesendet.',
            ],
        ],
    ],
    'privacy_gate' => [
        'metadata_only' => [
            'heading' => 'E-Mail-Inhalt und Betreff sind eingeschränkt',
            'description' => 'Sie können die Teilnehmer und den Zeitstempel sehen. Der vollständige E-Mail-Inhalt ist verborgen. Fordern Sie Zugriff an, um mehr zu sehen.',
        ],
        'subject_only' => [
            'heading' => 'E-Mail-Inhalt ist eingeschränkt',
            'description' => 'Sie können die Betreffzeile sehen. Der vollständige E-Mail-Inhalt ist verborgen. Fordern Sie Zugriff an, um mehr zu sehen.',
        ],
        'private' => [
            'heading' => 'Diese E-Mail ist privat',
            'description' => 'Nur der E-Mail-Besitzer kann diesen Inhalt sehen.',
        ],
        'request_hint' => 'Wählen Sie oben :action, um mehr Zugriff anzufordern.',
        'request_pending' => 'Ihre Zugriffsanfrage ist ausstehend.',
    ],
    'reader' => [
        'heading' => 'E-Mail anzeigen',
    ],
    'back_to_list' => 'Zurück zur Liste',
    'recipients' => [
        'from' => 'Von',
        'to' => 'An',
        'to_heading' => 'An',
        'cc' => 'CC',
        'cc_heading' => 'CC',
        'more' => '{1}und noch 1|[2,*]und :count weitere',
        'details' => 'Absender und Empfänger',
    ],
    'row_actions' => [
        'label' => 'E-Mail-Aktionen',
    ],
    'mark_all_read' => [
        'label' => 'Alle als gelesen markieren',
    ],
    'reply_forward' => [
        'modal_headings' => [
            'reply_all' => 'Allen antworten',
            'forward' => 'Weiterleiten',
            'reply' => 'Antworten',
        ],
        'notifications' => [
            'queued' => [
                'title' => 'E-Mail in die Warteschlange gestellt',
            ],
        ],
    ],
    'sharing' => [
        'label' => 'Freigabe',
        'modal_heading' => 'Freigabeeinstellungen',
        'fields' => [
            'privacy_tier' => [
                'label' => 'Wer kann diese E-Mail sehen?',
            ],
            'shares' => [
                'label' => 'Mit bestimmten Teammitgliedern teilen',
                'description' => 'Geben Sie bestimmten Personen mehr Zugriff als in der obigen Einstellung erlaubt.',
                'add_action_label' => 'Teammitglied hinzufügen',
                'new_item' => 'Neues Teammitglied',
            ],
            'shared_with' => [
                'label' => 'Teammitglied',
                'placeholder' => 'Wählen Sie ein Teammitglied…',
            ],
            'tier' => [
                'label' => 'Zugriffsstufe',
            ],
        ],
        'notifications' => [
            'saved' => [
                'title' => 'Freigabeeinstellungen gespeichert.',
            ],
        ],
    ],
    'request_access' => [
        'label' => 'Zugriff anfordern',
        'fields' => [
            'tier_requested' => [
                'label' => 'Angeforderte Zugriffsstufe',
            ],
        ],
        'notifications' => [
            'pending' => [
                'title' => 'Sie haben bereits eine ausstehende Anfrage für diese E-Mail.',
            ],
            'sent' => [
                'title' => 'Zugriffsanfrage gesendet.',
            ],
        ],
    ],
    'compose_form' => [
        'signature' => [
            'label' => 'Signatur',
        ],
    ],
];
