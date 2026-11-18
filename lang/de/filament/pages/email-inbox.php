<?php

declare(strict_types=1);

return [
    'navigation_label' => 'E-Mails',
    'account_filter' => [
        'label' => 'Postfach',
    ],
    'tabs' => [
        'drafts' => 'Entwürfe',
        'outbox' => 'Postausgang',
        'templates' => 'Vorlagen',
    ],
    'drafts' => [
        'columns' => [
            'subject' => 'Entwurf',
            'last_edited' => 'Zuletzt bearbeitet',
        ],
        'actions' => [
            'open' => 'Weiterschreiben',
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
            'heading' => 'Keine E-Mails im Postausgang',
            'description' => 'Eingeplante und terminierte E-Mails erscheinen hier, bis sie gesendet werden.',
        ],
    ],
    'search' => [
        'placeholder' => 'E-Mails suchen…',
        'clear' => 'Suche löschen',
    ],
    'subject' => [
        'none' => '(kein Betreff)',
        'hidden' => '(Betreff ausgeblendet)',
    ],
    'pagination' => [
        'previous' => 'Zurück',
        'next' => 'Weiter',
        'range' => ':first–:last von :total',
    ],
    'list_empty' => [
        'no_results' => 'Keine Ergebnisse für „:search“',
        'all' => 'Keine E-Mails',
        'sent' => 'Keine gesendeten E-Mails',
        'inbox' => 'Keine empfangenen E-Mails',
    ],
    'list_row' => [
        'via' => 'über :name',
        'via_mailboxes' => '{1}über 1 Postfach|[2,*]über :count Postfächer',
        'access_granted_via' => 'Zugriff gewährt über',
        'timestamp_yesterday' => 'Gestern, :time',
        'request_access' => 'Zugriff bei :name anfragen',
        'requested' => 'Angefragt',
        'opening' => 'Wird geöffnet…',
    ],
    'pending_access' => [
        'heading' => '{1}1 ausstehende Zugriffsanfrage|[2,*]:count ausstehende Zugriffsanfragen',
        'unknown_user' => 'Unbekannte Person',
        'approve' => 'Genehmigen',
        'deny' => 'Ablehnen',
    ],
    'compose' => [
        'label' => 'Verfassen',
        'notifications' => [
            'queued' => [
                'title' => 'E-Mail eingeplant',
                'body' => 'Ihre E-Mail wird gesendet.',
            ],
        ],
    ],
    'privacy_gate' => [
        'metadata_only' => [
            'heading' => 'Inhalt und Betreff der E-Mail sind eingeschränkt',
            'description' => 'Sie sehen Angaben zu Teilnehmern und Datum. Fragen Sie Zugriff an, um Betreff und Inhalt zu sehen.',
        ],
        'subject_only' => [
            'heading' => 'Der Inhalt der E-Mail ist eingeschränkt',
            'description' => 'Sie sehen die Betreffzeile. Der vollständige Inhalt ist ausgeblendet. Fragen Sie Zugriff an, um mehr zu sehen.',
        ],
        'private' => [
            'heading' => 'Diese E-Mail ist privat',
            'description' => 'Nur der Inhaber der E-Mail kann diesen Inhalt ansehen.',
        ],
        'request_hint' => 'Wählen Sie oben :action, um mehr Zugriff anzufragen.',
        'request_pending' => 'Ihre Zugriffsanfrage ist ausstehend.',
    ],
    'reader' => [
        'heading' => 'E-Mail ansehen',
        'internal' => 'Interne E-Mail. Nur Mitglieder des Arbeitsbereichs können sie sehen.',
        'unknown_sender' => '(unbekannter Absender)',
        'no_body' => '(kein Nachrichtentext)',
        'attachments' => [
            'unnamed' => 'Unbenannte Datei',
            'processing' => 'wird verarbeitet…',
        ],
    ],
    'back_to_list' => 'Zurück zur Liste',
    'recipients' => [
        'from' => 'Von',
        'to' => 'an',
        'to_heading' => 'An',
        'cc' => 'cc',
        'cc_heading' => 'Cc',
        'more' => '{1}und 1 weiterer|[2,*]und :count weitere',
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
                'title' => 'E-Mail eingeplant',
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
                'label' => 'Mit bestimmten Mitgliedern teilen',
                'description' => 'Geben Sie namentlich genannten Mitgliedern mehr Zugriff, als die obige Einstellung erlaubt.',
                'add_action_label' => 'Mitglied hinzufügen',
                'new_item' => 'Neues Mitglied',
            ],
            'shared_with' => [
                'label' => 'Mitglied',
                'placeholder' => 'Mitglied auswählen…',
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
        'label' => 'Zugriff anfragen',
        'fields' => [
            'tier_requested' => [
                'label' => 'Angefragte Zugriffsstufe',
            ],
        ],
        'notifications' => [
            'pending' => [
                'title' => 'Für diese E-Mail liegt bereits eine ausstehende Anfrage vor.',
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
