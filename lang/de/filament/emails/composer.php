<?php

declare(strict_types=1);

return [
    'title' => 'Neue E-Mail',
    'title_mass_send' => 'Neue Massen-E-Mail',
    'opening' => 'Öffnen des Erstellers…',
    'draft' => 'Entwurf',
    'quoted' => [
        'hidden' => 'Die ursprüngliche Nachricht wird nicht mit Ihnen geteilt.',
    ],
    'fields' => [
        'from' => 'Von',
        'to' => 'An',
        'cc' => 'CC',
        'bcc' => 'BCC',
        'subject' => 'Betreff',
        'message' => 'Nachricht',
        'signature_none' => 'Keine Signatur',
        'signature_name' => 'Signaturname',
        'signature_content' => 'Signatur',
        'signature_default' => 'Als meine Standard-Signatur verwenden',
        'template_none' => 'Noch keine Vorlagen',
        'subject_placeholder' => 'Betreff hinzufügen',
        'body_placeholder' => 'Schreiben Sie Ihre Nachricht…',
        'company_team' => 'Unternehmensteam',
        'company_team_people' => '{1}1 Person|[2,*]:count Personen',
    ],
    'toolbar' => [
        'paragraph' => 'Absatz',
        'alignment' => 'Ausrichtung',
        'lists' => 'Listen',
    ],

    'mass_send' => [
        'summary' => '{0}Noch keine Empfänger|{1}Versand an 1 Empfänger|[2,*]Einzelne E-Mail an jeden von :count Empfängern senden',
        'toggle' => 'Massenversand',
        'send_button' => 'E-Mails senden (:count)',
        'add_recipients' => 'Empfänger hinzufügen',
        'outbox_hint' => 'Die Lieferzeit hängt von den Elementen in Ihrem Posteingang ab.',
        'view_outbox' => 'Posteingang anzeigen',
        'no_recipients' => 'Fügen Sie mindestens einen Empfänger hinzu, bevor Sie senden.',
    ],
    'actions' => [
        'send' => 'E-Mail senden',
        'attach' => 'Dateien anhängen',
        'signature' => 'Signatur',
        'template' => 'Vorlage verwenden',
        'create_signature' => 'Neue Signatur erstellen',
        'create_template' => 'Als Vorlage speichern',
        'variable' => 'Variable einfügen',
        'remove_recipient' => 'Entfernen',
        'download_attachment' => 'Anhang herunterladen',
        'remove_attachment' => 'Anhang entfernen',
        'uploading' => 'Hochladen…',
        'expand' => 'Vollbild',
        'shrink' => 'Vollbild beenden',
        'minimize' => 'Minimieren',
        'restore' => 'Wiederherstellen',
        'close' => 'Schließen',
        'discard' => 'Entwurf verwerfen',
        'grant_send' => [
            'label' => 'Sendeberechtigung erteilen',
        ],
    ],
    'grant_send' => [
        'heading' => "Relaticle kann noch nicht von :email senden.",
        'heading_generic' => "Relaticle kann noch nicht von diesem Konto senden.",
        'description' => 'Erteilen Sie die Sendeberechtigung, um dies zu beheben.',
    ],
    'notifications' => [
        'mass_queued' => [
            'title' => 'Massen-E-Mail in die Warteschlange gestellt',
            'body' => '{1}Versand an 1 Empfänger.|[2,*]Versand an :count Empfänger.',
        ],
        'queued' => ['title' => 'E-Mail in die Warteschlange gestellt'],
        'outbox_full' => ['title' => 'E-Mail konnte nicht in die Warteschlange gestellt werden'],
        'signature_created' => ['title' => 'Signatur erstellt'],
        'template_created' => ['title' => 'Vorlage gespeichert'],
        'attachment_too_large' => [
            'title' => 'Einige Dateien waren zu groß',
            'body' => 'Nicht angehängt: :files. Jede Datei muss unter :max liegen, und alle Anhänge zusammen unter :total.',
        ],
        'attachment_unavailable' => [
            'title' => 'Einige Anhänge konnten nicht eingeschlossen werden',
            'body' => 'Die E-Mail wurde nicht gesendet. Diese Dateien konnten nicht heruntergeladen werden: :files. Entfernen Sie diese, oder versuchen Sie es erneut.',
        ],
        'send_attachment_unavailable' => [
            'title' => 'Einige Anhänge konnten nicht eingeschlossen werden',
            'body' => 'Die E-Mail wurde nicht gesendet. Diese Dateien konnten nicht heruntergeladen werden: :files. Entfernen Sie diese, oder versuchen Sie es erneut.',
        ],
        'draft_account_disconnected' => [
            'title' => 'Ursprüngliches Konto nicht mehr verbunden',
            'body' => 'Das Konto, von dem dieser Entwurf geschrieben wurde, ist nicht mehr verbunden, daher wurde er auf Ihr Standardkonto umgeschaltet. Überprüfen Sie den Absender, bevor Sie senden.',
        ],
        'draft_saved' => [
            'title' => 'Entwurf gespeichert',
        ],
    ],
];
