<?php

declare(strict_types=1);

return [
    'create_workspace' => [
        'label' => 'Arbeitsbereich erstellen',
        'steps' => [
            'workspace' => 'Arbeitsbereich',
            'attribution' => 'Zuschreibung',
        ],
        'actions' => [
            'continue' => 'Fortfahren',
            'get_started' => 'Jetzt starten',
            'cancel' => 'Abbrechen',
            'back' => 'Zurück',
        ],

        'headings' => [
            'workspace' => 'Erstellen Sie Ihren Arbeitsbereich',
            'attribution' => 'Wie haben Sie von uns erfahren?',
            'attribution_description' => 'Bitte wählen Sie unten aus, wo Sie von Relaticle erfahren haben. Dieser Schritt ist optional.',
            'use_case' => 'Helfen Sie uns, Ihren Arbeitsbereich anzupassen',
            'use_case_description' => 'Bei Relaticle geht es darum, Ihnen zu ermöglichen, das CRM zu bauen, das Sie genau benötigen, egal wie komplex es ist.',
            'use_case_hint' => 'Erzählen Sie uns von Ihrem Anwendungsfall und wir richten eine Pipeline-Vorlage ein, die dazu passt.',
        ],
        'form' => [
            'your_name' => [
                'label' => 'Ihr Name',
                'placeholder' => 'Jane Doe',
            ],
            'company_logo' => [
                'label' => 'Unternehmen-Logo',
            ],
            'workspace_name' => [
                'label' => 'Unternehmensname',
                'placeholder' => 'Geben Sie Ihren Unternehmensnamen ein',
            ],
            'workspace_handle' => [
                'label' => 'Arbeitsbereich-Handle',
                'placeholder' => 'mein-arbeitsbereich',
                'helper_text' => 'Nur Kleinbuchstaben, Zahlen und Bindestriche sind erlaubt.',
            ],
            'use_case_label' => 'Wofür werden Sie Relaticle verwenden?',
            'use_case_validation_attribute' => 'Anwendungsfall',
            'use_case_context_label' => 'Wählen Sie aus, was auf Sie zutrifft.',
            'use_case_context_validation_attribute' => 'Anwendungsfalldetails',
            'other_use_case_label' => 'Was werden Sie verfolgen?',
            'other_use_case_placeholder' => 'Kandidaten, Spender, Großhandelshändler',
            'other_use_case_validation_attribute' => 'Was Sie verfolgen',
            'referral_detail_label' => 'Welcher Assistent war es?',
            'referral_prompt_label' => 'Was haben Sie ihn gefragt?',
            'referral_prompt_placeholder' => 'Ein Open-Source-CRM für ein kleines Vertriebsteam',
            'referral_prompt_validation_attribute' => 'Was Sie gefragt haben',
        ],
        'notifications' => [
            'workspace_created' => [
                'title' => 'Arbeitsbereich erstellt',
                'body' => 'Ihr Arbeitsbereich ":name" ist einsatzbereit.',
            ],
            'workspace_limit_reached' => [
                'title' => 'Arbeitsbereich-Limit erreicht',
                'body' => 'Sie besitzen bereits die maximale Anzahl an Arbeitsbereichen. Löschen Sie einen oder bitten Sie darum, zu einem bestehenden Arbeitsbereich hinzugefügt zu werden.',
            ],
        ],
        'preview' => [
            'company_placeholder' => 'Ihr Unternehmen',
        ],
        'validation' => [
            'context_required' => 'Wählen Sie mindestens eine Option für den ausgewählten Anwendungsfall aus.',
            'context_invalid' => 'Eine der ausgewählten Optionen gehört nicht zum ausgewählten Anwendungsfall.',
            'referral_detail_invalid' => 'Der ausgewählte Assistent gehört nicht zur ausgewählten Quelle.',
        ],
    ],
    'setup_workspace' => [
        'title' => 'Richten Sie Ihren Arbeitsbereich ein',
        'email' => [
            'heading' => 'Beginnen Sie mit den Personen, mit denen Sie bereits E-Mails austauschen',
            'description' => 'Verbinden Sie Ihr Postfach und Relaticle erstellt Ihr CRM, während Sie die Einrichtung abschließen.',
            'google' => 'Fortfahren mit Google',
            'microsoft' => 'Fortfahren mit Microsoft',
            'privacy' => 'Nur Sie können Ihre E-Mails lesen. Sie entscheiden, was Ihre Teammitglieder sehen.',
            'benefit_records' => 'Personen und Unternehmen, mit denen Sie E-Mails austauschen, werden für Sie hinzugefügt',
            'benefit_timeline' => 'E-Mails und Termine auf der richtigen Aufzeichnung',
            'benefit_send' => 'Senden Sie aus Relaticle mit Ihrer eigenen Adresse',
            'skip' => "Ich werde Personen und Unternehmen selbst hinzufügen",
            'skip_confirm' => [
                'heading' => 'Ohne Ihr Postfach fortfahren?',
                'description' => 'Die Verbindung Ihres Postfachs macht diese Arbeit für Sie.',
                'submit' => "Ja, ich bin sicher",
            ],
        ],
        'sharing' => [
            'heading' => 'Wählen Sie aus, was Ihr Team sieht',
            'syncing' => 'Ihr Postfach wird synchronisiert.',
            'description' => 'Nur Sie können Ihre E-Mails lesen. Wählen Sie aus, wie sie für andere Mitglieder erscheinen.',
            'connected' => 'Verbunden',
            'footer' => 'Sie können dies jederzeit in den Einstellungen ändern.',
        ],
        'invite' => [
            'heading' => 'Laden Sie Ihr Team ein',
            'description' => 'Fügen Sie die Personen hinzu, mit denen Sie zusammenarbeiten. Sie erhalten eine E-Mail mit einem Link zum Beitreten.',
            'emails_label' => 'E-Mail-Adressen',
            'emails_placeholder' => 'maya@acme.com, leo@acme.com',
            'helper' => 'Fügen Sie mehrere auf einmal ein. Alle erhalten dieselbe Rolle.',
            'invalid_emails' => 'Überprüfen Sie diese Adressen: :emails',
            'link_label' => 'Oder teilen Sie einen Einladungslink',
            'copy_link' => 'Link kopieren',
            'create_link' => 'Link erstellen',
            'send_and_start' => 'Einladungen senden und starten',
            'not_sent' => [
                'title' => 'Einige Einladungen wurden nicht gesendet',
                'body' => 'Ihr Arbeitsbereich ist bereit. Überprüfen Sie die Mitglieder in den Einstellungen und laden Sie alle noch fehlenden Personen ein.',
            ],
        ],
        'preview' => [
            'people' => 'Personen',
            'person' => 'Person',
            'company' => 'Unternehmen',
            'from_mailbox' => 'Aus Ihrem Postfach',
            'found' => '{0} Ich lese Ihr Postfach|{1} :count Person gefunden|[2,*] :count Personen gefunden',
        ],
    ],
];
