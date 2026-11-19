<?php

declare(strict_types=1);

return [
    'title' => 'Neue E-Mail',
    'title_mass_send' => 'Neue Massen-E-Mail',
    'opening' => 'Der Editor wird geöffnet…',
    'draft' => 'Entwurf',
    'quoted' => [
        'hidden' => 'Die ursprüngliche Nachricht ist nicht mit Ihnen geteilt.',
    ],
    'fields' => [
        'from' => 'Von',
        'to' => 'An',
        'cc' => 'CC',
        'bcc' => 'BCC',
        'subject' => 'Betreff',
        'message' => 'Nachricht',
        'signature_none' => 'Keine Signatur',
        'signature_name' => 'Name der Signatur',
        'signature_content' => 'Signatur',
        'signature_default' => 'Als meine Standardsignatur verwenden',
        'template_none' => 'Noch keine Vorlagen',
        'subject_placeholder' => 'Betreff hinzufügen',
        'body_placeholder' => 'Nachricht schreiben…',
        'company_team' => 'Unternehmensteam',
        'company_team_people' => '{1}1 Person|[2,*]:count Personen',
    ],
    'toolbar' => [
        'paragraph' => 'Absatz',
        'alignment' => 'Ausrichtung',
        'lists' => 'Listen',
    ],

    'mass_send' => [
        'summary' => '{0}Noch keine Empfänger|{1}Senden an 1 Empfänger|[2,*]Separate E-Mail an jeden von :count Empfängern',
        'toggle' => 'Massenversand',
        'send_button' => 'E-Mails senden (:count)',
        'add_recipients' => 'Empfänger hinzufügen',
        'outbox_hint' => 'Die Zustellzeit hängt von den Einträgen in Ihrem Postausgang ab.',
        'view_outbox' => 'Postausgang ansehen',
        'no_recipients' => 'Fügen Sie vor dem Senden mindestens einen Empfänger hinzu.',
    ],
    'actions' => [
        'send' => 'E-Mail senden',
        'attach' => 'Dateien anhängen',
        'signature' => 'Signatur',
        'template' => 'Vorlage verwenden',
        'create_signature' => 'Neue Signatur',
        'create_template' => 'Als Vorlage speichern',
        'variable' => 'Variable einfügen',
        'remove_recipient' => 'Entfernen',
        'download_attachment' => 'Anhang herunterladen',
        'remove_attachment' => 'Anhang entfernen',
        'uploading' => 'Wird hochgeladen…',
        'expand' => 'Vollbild',
        'shrink' => 'Vollbild beenden',
        'minimize' => 'Minimieren',
        'restore' => 'Wiederherstellen',
        'close' => 'Schließen',
        'discard' => 'Entwurf verwerfen',
        'grant_send' => [
            'label' => 'Berechtigung erteilen',
        ],
    ],
    'grant_send' => [
        'heading' => 'Relaticle kann noch nicht von :email senden.',
        'heading_generic' => 'Relaticle kann von diesem Postfach noch nicht senden.',
        'description' => 'Erteilen Sie die Sendeberechtigung, um das zu beheben.',
    ],
    'notifications' => [
        'mass_queued' => [
            'title' => 'Massen-E-Mail eingeplant',
            'body' => '{1}Senden an 1 Empfänger.|[2,*]Senden an :count Empfänger.',
        ],
        'queued' => ['title' => 'E-Mail zum Senden eingeplant'],
        'outbox_full' => ['title' => 'Die E-Mail konnte nicht eingeplant werden'],
        'signature_created' => ['title' => 'Signatur erstellt'],
        'template_created' => ['title' => 'Vorlage gespeichert'],
        'attachment_too_large' => [
            'title' => 'Einige Dateien waren zu groß',
            'body' => 'Nicht angehängt: :files. Jede Datei muss kleiner als :max sein, alle Anhänge zusammen kleiner als :total.',
        ],
        'attachment_empty' => [
            'title' => 'Einige Dateien waren leer',
            'body' => 'Nicht angehängt: :files. Eine leere Datei enthält nichts zum Senden.',
        ],
        'attachment_type_not_allowed' => [
            'title' => 'Einige Dateien konnten nicht angehängt werden',
            'body' => 'Nicht angehängt: :files. Hängen Sie eine PDF-, Office- oder OpenDocument-Datei, eine CSV- oder Textdatei, eine Kalendereinladung, ein Bild oder ein ZIP-Archiv an.',
        ],
        'attachment_too_large_for_provider' => 'Jede Datei muss kleiner als :max sein, alle Anhänge zusammen nach der Kodierung kleiner als :total.',
        'attachment_unavailable' => [
            'title' => 'Einige Anhänge konnten nicht eingefügt werden',
            'body' => 'Nicht angehängt: :files. Laden Sie sie aus der ursprünglichen E-Mail herunter und fügen Sie sie hier hinzu, falls Sie sie noch brauchen.',
        ],
        'send_attachment_unavailable' => [
            'title' => 'Einige Anhänge konnten nicht eingefügt werden',
            'body' => 'Die E-Mail wurde nicht gesendet. Diese Dateien konnten nicht heruntergeladen werden: :files. Entfernen Sie sie oder senden Sie erneut.',
        ],
        'draft_account_disconnected' => [
            'title' => 'Ursprüngliches Postfach nicht mehr verbunden',
            'body' => 'Das Postfach, von dem dieser Entwurf geschrieben wurde, ist nicht mehr verbunden. Er wurde auf Ihr Standardpostfach umgestellt. Prüfen Sie den Absender vor dem Senden.',
        ],
        'draft_saved' => [
            'title' => 'Entwurf gespeichert',
        ],
    ],
    'validation' => [
        'body_required' => 'Schreiben Sie vor dem Senden eine Nachricht.',
    ],
];
