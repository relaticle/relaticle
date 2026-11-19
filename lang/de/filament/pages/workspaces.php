<?php

declare(strict_types=1);

return [
    'create_workspace' => [
        'label' => 'Arbeitsbereich erstellen',
        'steps' => [
            'workspace' => 'Arbeitsbereich',
            'attribution' => 'Herkunft',
        ],
        'actions' => [
            'continue' => 'Weiter',
            'get_started' => 'Loslegen',
            'cancel' => 'Abbrechen',
            'back' => 'Zurück',
        ],
        'headings' => [
            'workspace' => 'Erstellen Sie Ihren Arbeitsbereich',
            'attribution' => 'Wie haben Sie von uns erfahren?',
            'attribution_description' => 'Bitte wählen Sie unten aus, wo Sie von Relaticle erfahren haben. Dieser Schritt ist optional.',
            'use_case' => 'Helfen Sie uns, Ihren Arbeitsbereich anzupassen',
            'use_case_description' => 'Mit Relaticle bauen Sie genau das CRM, das Sie brauchen, egal wie komplex.',
            'use_case_hint' => 'Beschreiben Sie Ihren Anwendungsfall. Wir richten eine passende Pipeline-Vorlage ein.',
        ],
        'form' => [
            'your_name' => [
                'label' => 'Ihr Name',
                'placeholder' => 'Erika Mustermann',
            ],
            'company_logo' => [
                'label' => 'Unternehmenslogo',
            ],
            'workspace_name' => [
                'label' => 'Unternehmensname',
                'placeholder' => 'Geben Sie den Namen Ihres Unternehmens ein',
            ],
            'workspace_handle' => [
                'label' => 'Kürzel des Arbeitsbereichs',
                'placeholder' => 'mein-arbeitsbereich',
                'helper_text' => 'Erlaubt sind nur Kleinbuchstaben, Zahlen und Bindestriche.',
            ],
            'use_case_label' => 'Wofür möchten Sie Relaticle nutzen?',
            'use_case_validation_attribute' => 'Anwendungsfall',
            'use_case_context_label' => 'Wählen Sie aus, was auf Sie zutrifft.',
            'use_case_context_validation_attribute' => 'Details zum Anwendungsfall',
            'other_use_case_label' => 'Was möchten Sie verfolgen?',
            'other_use_case_placeholder' => 'Bewerber, Spender, Großhandelskunden',
            'other_use_case_validation_attribute' => 'was Sie verfolgen',
            'referral_detail_label' => 'Welcher Assistent war es?',
            'referral_prompt_label' => 'Was haben Sie ihn gefragt?',
            'referral_prompt_placeholder' => 'Ein Open-Source-CRM für einen kleinen Vertrieb',
            'referral_prompt_validation_attribute' => 'Ihre Frage',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Arbeitsbereich erstellt',
                'body' => 'Ihr Arbeitsbereich „:name“ ist einsatzbereit.',
            ],
            'workspace_limit_reached' => [
                'title' => 'Limit für Arbeitsbereiche erreicht',
                'body' => 'Sie sind bereits Inhaber der maximalen Anzahl an Arbeitsbereichen. Löschen Sie einen oder bitten Sie um eine Einladung in einen bestehenden Arbeitsbereich.',
            ],
        ],
        'preview' => [
            'company_placeholder' => 'Ihr Unternehmen',
        ],
        'validation' => [
            'context_required' => 'Wählen Sie mindestens eine Option für den gewählten Anwendungsfall.',
            'context_invalid' => 'Eine der gewählten Optionen gehört nicht zum gewählten Anwendungsfall.',
            'referral_detail_invalid' => 'Der gewählte Assistent gehört nicht zur gewählten Quelle.',
        ],
    ],
    'setup_workspace' => [
        'title' => 'Richten Sie Ihren Arbeitsbereich ein',
        'email' => [
            'heading' => 'Beginnen Sie mit den Personen, denen Sie bereits schreiben',
            'description' => 'Verbinden Sie Ihr Postfach. Relaticle baut Ihr CRM auf, während Sie die Einrichtung abschließen.',
            'google' => 'Weiter mit Google',
            'microsoft' => 'Weiter mit Microsoft',
            'privacy' => 'Nur Sie können Ihre E-Mails lesen. Danach legen Sie fest, was Ihr Team sieht.',
            'benefit_records' => 'Personen und Unternehmen, denen Sie schreiben, werden für Sie angelegt',
            'benefit_timeline' => 'E-Mails und Meetings beim richtigen Eintrag',
            'benefit_send' => 'Mit Ihrer eigenen Adresse aus Relaticle senden',
            'skip' => 'Ich lege Personen und Unternehmen selbst an',
        ],
        'sharing' => [
            'heading' => 'Legen Sie fest, was Ihr Team sieht',
            'syncing' => 'Ihr Postfach wird synchronisiert.',
            'description' => 'Nur Sie können Ihre E-Mails lesen. Wählen Sie, wie sie anderen Mitgliedern angezeigt werden.',
            'connected' => 'Verbunden',
            'footer' => 'Sie können das jederzeit in den Einstellungen ändern.',
        ],
        'invite' => [
            'heading' => 'Laden Sie Ihr Team ein',
            'description' => 'Fügen Sie die Menschen hinzu, mit denen Sie arbeiten. Jede eingeladene Person erhält eine E-Mail mit einem Link zum Beitreten.',
            'emails_label' => 'E-Mail-Adressen',
            'emails_placeholder' => 'maya@acme.com, leo@acme.com',
            'helper' => 'Fügen Sie mehrere auf einmal ein. Alle erhalten dieselbe Rolle.',
            'invalid_emails' => 'Prüfen Sie diese Adressen: :emails',
            'send_and_start' => 'Einladungen senden und loslegen',
            'not_sent' => [
                'title' => 'Einige Einladungen wurden nicht gesendet',
                'body' => 'Ihr Arbeitsbereich ist bereit. Prüfen Sie Mitglieder in den Einstellungen und laden Sie alle ein, die fehlen.',
            ],
        ],
        'preview' => [
            'people' => 'Personen',
            'person' => 'Person',
            'company' => 'Unternehmen',
            'from_mailbox' => 'Aus Ihrem Postfach',
            'found' => '{0} Ihr Postfach wird gelesen|{1} :count Person gefunden|[2,*] :count Personen gefunden',
        ],
    ],
];
