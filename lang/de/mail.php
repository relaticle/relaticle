<?php

declare(strict_types=1);

return [
    'fallback_link' => 'Wenn die Schaltfläche nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:',

    'footer' => [
        'settings' => 'Benachrichtigungseinstellungen',
        'unsubscribe' => 'Tägliche Zusammenfassung abbestellen',
        'copyright' => '© :year :company',
        'reason' => [
            'owner' => 'Sie erhalten diese Nachricht, weil Sie Inhaber des Arbeitsbereichs :workspace sind.',
            'member' => 'Sie erhalten diese Nachricht, weil Sie Mitglied von :workspace sind.',
            'former_member' => 'Sie erhalten diese Nachricht, weil Sie Mitglied von :workspace waren.',
            'former_owner' => 'Sie erhalten diese Nachricht, weil Sie Inhaber des Arbeitsbereichs :workspace waren.',
            'digest' => 'Sie erhalten diese Nachricht, weil Sie die tägliche Zusammenfassung aktiviert haben.',
            'assignee' => 'Sie erhalten diese Nachricht, weil Ihnen in :workspace eine Aufgabe zugewiesen wurde.',
            'invitee' => 'Sie erhalten diese Nachricht, weil :email in :workspace eingeladen wurde.',
            'contact' => 'Sie erhalten diese Nachricht, weil jemand das Kontaktformular abgeschickt hat.',
            'account' => 'Sie erhalten diese Nachricht wegen einer Anfrage zu Ihrem Konto bei :company.',
            'onboarding' => 'Sie erhalten diese Nachricht, weil Sie einen Arbeitsbereich bei :company erstellt haben.',
            'billing_contact' => 'Sie erhalten diese Nachricht, weil Sie der Ansprechpartner für die Abrechnung von :workspace sind.',
        ],
    ],

    'unsubscribe' => [
        'title' => 'Abbestellen',
        'heading' => 'Tägliche Zusammenfassung beenden?',
        'body' => 'Sie erhalten die morgendliche Aufgabenzusammenfassung an :email nicht mehr. Sie können sie in den Benachrichtigungseinstellungen wieder aktivieren.',
        'confirm' => 'Abbestellen',
        'done_heading' => 'Sie haben abbestellt',
        'done_body' => 'Die tägliche Zusammenfassung ist für :email deaktiviert.',
        'settings' => 'Benachrichtigungseinstellungen',
    ],

    'trial_ending' => [
        'subject' => 'Ihre Pro-Testphase endet am :date',
        'preheader' => 'Dann wird :workspace pausiert, sofern Sie keinen Tarif wählen. Ihre Einträge bleiben sicher.',
        'preheader_grandfathered' => 'Dann kehrt :workspace zu seinem Cloud-Free-Tarif zurück. Ihre Einträge bleiben sicher.',
        'heading' => 'Pro für :workspace endet am :date',
        'ends_at' => 'Ihre Pro-Testphase endet am :datetime (Zeitzone: :zone).',
        'pauses' => 'Danach wird :workspace pausiert, bis Sie einen Tarif wählen.',
        'kept' => 'Meldet sich danach 60 Tage lang niemand an, löschen wir den Arbeitsbereich und seine Einträge.',
        'keeps' => 'Mit Pro behalten Sie alle KI-Modelle, :credits Credits pro Monat und höhere Limits. Ein Pauschalpreis deckt den gesamten Arbeitsbereich ab.',
        'grandfathered' => 'Wenn Sie nichts tun, kehrt :workspace zu seinem bestehenden Cloud-Free-Tarif zurück. Ihre Daten bleiben unberührt.',
        'cta' => 'Tarif wählen',
        'questions' => 'Fragen? Antworten Sie auf diese E-Mail.',
    ],

    'trial_usage' => [
        'so_far' => 'Bisher hat Ihr Team :parts.',
        'during' => 'Während der Testphase hat Ihr Team :parts.',
        'records' => ':count Eintrag hinzugefügt|:count Einträge hinzugefügt',
        'questions' => 'Rela :count Frage gestellt|Rela :count Fragen gestellt',
        'both' => ':records und :questions',
    ],

    'pro_ended' => [
        'trial_ended' => [
            'subject' => 'Ihre Pro-Testphase für :workspace ist beendet',
            'heading' => 'Ihre Pro-Testphase für :workspace ist beendet',
            'preheader' => 'Ihre Einträge sind sicher. Wählen Sie einen Tarif oder verlängern Sie die Testphase um :days Tage.',
        ],
        'extension_ended' => [
            'subject' => 'Ihre verlängerte Testphase für :workspace ist beendet',
            'heading' => 'Ihre verlängerte Testphase für :workspace ist beendet',
            'preheader' => 'Ihre Einträge sind sicher. Wählen Sie einen Tarif und machen Sie dort weiter, wo Sie aufgehört haben.',
        ],
        'subscription_ended' => [
            'subject' => 'Ihr Cloud-Abonnement ist beendet',
            'heading' => 'Der Cloud-Zugriff für :workspace ist beendet',
        ],
        'managed_ended' => [
            'subject' => 'Ihr Tarif :plan ist beendet',
            'heading' => ':plan für :workspace ist beendet',
            'preheader' => 'Ihre Einträge sind sicher. Sprechen Sie uns an, um :plan neu zu starten.',
            'grandfathered' => ':workspace nutzt wieder seinen bestehenden Cloud-Free-Tarif. Ihre Daten bleiben unberührt.',
            'restore' => 'Sprechen Sie uns an, um :plan neu zu starten. Ihre Einträge bleiben, wie sie sind.',
            'cta' => 'Sprechen Sie uns an',
            'mailbox_sync_paused' => 'Auch die E-Mail- und Kalendersynchronisierung ist pausiert. Sie wird fortgesetzt, sobald :plan neu startet.',
        ],
        'trial_paused' => ':workspace ist pausiert. Ihre Einträge sind sicher.',
        'kept' => 'Meldet sich niemand an, löschen wir :workspace am :date. Wählen Sie vorher einen Tarif, um den Arbeitsbereich zu behalten.',
        'extend' => 'Brauchen Sie mehr Zeit? [Verlängern Sie Ihre Testphase um :days Tage](:url) auf der Abrechnungsseite. Das geht einmal.',
        'preheader' => 'Ihre Einträge sind sicher. Schließen Sie ein Abonnement ab, um den Arbeitsbereich wieder zu öffnen.',
        'preheader_grandfathered' => 'Ihr Arbeitsbereich nutzt wieder seinen Cloud-Free-Tarif.',
        'paused' => 'Der Cloud-Zugriff auf :workspace ist jetzt pausiert. Ihre Einträge sind sicher, und nichts wurde gelöscht.',
        'mailbox_sync_paused' => 'Auch die E-Mail- und Kalendersynchronisierung ist pausiert. Sie wird fortgesetzt, sobald Sie ein Abonnement abschließen.',
        'restore' => 'Schließen Sie einen Cloud-Tarif ab, um App, REST-API, MCP-Server und KI-Assistent genau dort wieder zu öffnen, wo Sie aufgehört haben.',
        'grandfathered' => ':workspace nutzt wieder seinen bestehenden Cloud-Free-Tarif. Ihre Daten bleiben unberührt, und Sie können jederzeit wieder ein Abonnement abschließen.',
        'cta' => 'Tarif wählen',
        'cta_grandfathered' => 'Tarif wählen',
    ],

    'managed_plan_invoice' => [
        'subject' => 'Ihre Rechnung für :plan von Relaticle',
        'preheader' => 'Ihre Jahresrechnung für :plan und :workspace ist bereit',
        'heading' => 'Ihre Rechnung für :plan ist bereit',
        'line' => 'Ihre Jahresrechnung für :plan und :workspace beträgt :amount und ist am :date fällig. Der Tarif wird aktiviert, sobald sie bezahlt ist.',
        'line_without_due' => 'Ihre Jahresrechnung für :plan und :workspace beträgt :amount. Der Tarif wird aktiviert, sobald sie bezahlt ist.',
        'cta' => 'Rechnung ansehen und bezahlen',
    ],

    'setup_nudge' => [
        'subject' => 'Ihr Arbeitsbereich wartet',
        'preheader' => 'Ein Schritt bringt :workspace in Gang: :step',
        'heading' => ':name, :workspace ist bereit für Ihre eigenen Einträge',
        'step' => 'Nächster Schritt: :step.',
        'cta' => 'Weiter mit :assistant',
    ],

    'task_assigned' => [
        'subject' => 'Neue Aufgabe: :title',
        'preheader' => 'Ihnen in :workspace zugewiesen',
        'preheader_without_workspace' => 'Ihnen wurde eine Aufgabe zugewiesen',
        'heading' => 'Sie haben eine neue Aufgabe',
        'workspace_label' => 'Arbeitsbereich',
        'cta' => 'Aufgabe ansehen',
    ],

    'task_digest' => [
        'subject_both' => '[:workspace] :overdue überfällig, :due heute fällig, :date',
        'subject_overdue' => '[:workspace] :count überfällige Aufgabe, :date|[:workspace] :count überfällige Aufgaben, :date',
        'subject_due' => '[:workspace] :count heute fällige Aufgabe, :date|[:workspace] :count heute fällige Aufgaben, :date',
        'preheader_more' => ':tasks und :count weitere',
        'heading' => 'Guten Morgen, :name',
        'summary' => 'Sie haben :tasks in :workspace.',
        'summary_both' => 'Sie haben :overdue und :due in :workspace.',
        'open_hint' => 'Wählen Sie eine Aufgabe, um sie in :workspace zu öffnen.',
        'summary_overdue' => ':count überfällige Aufgabe|:count überfällige Aufgaben',
        'summary_due' => ':count heute fällige Aufgabe|:count heute fällige Aufgaben',
        'overdue' => 'Überfällig',
        'due_today' => 'Heute fällig',
        'due_overdue' => ':date (überfällig)',
        'due_today_chip' => 'Heute fällig',
        'more_records' => '+:count',
        'more_tasks' => ':count weitere Aufgabe ansehen|:count weitere Aufgaben ansehen',
        'cta' => 'Meine Aufgaben öffnen',
    ],

    'workspace_invitation' => [
        'subject' => ':inviter hat Sie zu :workspace eingeladen',
        'subject_without_inviter' => 'Sie wurden zu :workspace eingeladen',
        'preheader' => ':workspace auf Relaticle beitreten als :role',
        'heading' => ':workspace beitreten',
        'line_with_inviter' => ':inviter hat Sie als :role in den Arbeitsbereich :workspace auf Relaticle eingeladen.',
        'line' => 'Sie wurden als :role in den Arbeitsbereich :workspace auf Relaticle eingeladen.',
        'expiry' => 'Diese Einladung läuft :expiry ab.',
        'ignore' => 'Sie erwarten das nicht? Ignorieren Sie diese E-Mail.',
        'cta' => 'Einladung annehmen',
    ],

    'workspace_deletion_scheduled' => [
        'subject' => ':workspace ist zur Löschung vorgemerkt',
        'preheader' => 'Löschung am :date. Bis dahin jederzeit abbrechbar',
        'heading' => ':workspace wird am :date gelöscht',
        'removes' => 'Personen, Unternehmen, Aufgaben, Verkaufschancen, Notizen und alle weiteren Einträge in :workspace werden nach diesem Datum entfernt.',
        'cancel' => 'Sie können die Löschung bis dahin jederzeit in den Einstellungen des Arbeitsbereichs abbrechen.',
        'cta' => 'Löschung abbrechen',
    ],

    'workspace_deletion_reminder' => [
        'subject' => ':workspace wird in :days Tag gelöscht|:workspace wird in :days Tagen gelöscht',
        'preheader' => 'Letzte Erinnerung vor dem :date',
        'heading' => ':days Tag bis zur Löschung von :workspace|:days Tage bis zur Löschung von :workspace',
        'final' => 'Das ist die letzte Erinnerung. Alles in :workspace wird nach dem :date entfernt.',
        'cancel' => 'Sie können die Löschung bis dahin jederzeit in den Einstellungen des Arbeitsbereichs abbrechen.',
        'cta' => 'Löschung abbrechen',
    ],

    'workspace_deletion_cancelled' => [
        'subject' => 'Löschung von :workspace abgebrochen',
        'preheader' => 'Ihre Daten sind sicher',
        'heading' => ':workspace bleibt bestehen',
        'body' => 'Die geplante Löschung von :workspace wurde abgebrochen. Es wurde nichts entfernt.',
        'cta' => ':workspace öffnen',
    ],

    'workspace_member_removed' => [
        'subject' => 'Sie wurden aus :workspace entfernt',
        'preheader' => 'Sie haben keinen Zugriff mehr auf diesen Arbeitsbereich',
        'heading' => 'Sie wurden aus :workspace entfernt',
        'body' => 'Ihr Zugriff auf :workspace und seine Einträge wurde beendet. Ihre anderen Arbeitsbereiche sind nicht betroffen.',
        'cta' => 'Relaticle öffnen',
    ],

    'account_deletion_scheduled' => [
        'subject' => 'Ihr Konto ist zur Löschung vorgemerkt',
        'preheader' => 'Löschung am :date. Zum Abbrechen anmelden',
        'heading' => 'Ihr Konto wird am :date gelöscht',
        'removes' => 'Ihr Profil und jeder Arbeitsbereich, dessen Inhaber Sie sind, werden nach diesem Datum entfernt.',
        'cancel' => 'Anders entschieden? Melden Sie sich bis dahin an, und die Löschung wird abgebrochen.',
        'cta' => 'Mein Konto behalten',
    ],

    'account_deletion_reminder' => [
        'subject' => 'Ihr Konto wird in :days Tag gelöscht|Ihr Konto wird in :days Tagen gelöscht',
        'preheader' => 'Letzte Erinnerung vor dem :date',
        'heading' => ':days Tag bis zur Löschung Ihres Kontos|:days Tage bis zur Löschung Ihres Kontos',
        'final' => 'Das ist die letzte Erinnerung. Ihr Konto und seine Daten werden nach dem :date entfernt.',
        'cancel' => 'Melden Sie sich bis dahin an, und die Löschung wird abgebrochen.',
        'cta' => 'Mein Konto behalten',
    ],

    'account_deletion_cancelled' => [
        'subject' => 'Ihr Konto bleibt bestehen',
        'preheader' => 'Löschung abgebrochen, Daten unberührt',
        'heading' => 'Willkommen zurück, :name',
        'body' => 'Die geplante Löschung Ihres Kontos wurde abgebrochen. Es wurde nichts entfernt.',
        'cta' => 'Relaticle öffnen',
    ],

    'verify_email' => [
        'subject' => 'E-Mail-Adresse bestätigen',
        'preheader' => 'Ein Klick schließt die Registrierung ab',
        'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
        'body' => 'Bestätigen Sie diese Adresse, um die Einrichtung Ihres Relaticle-Kontos abzuschließen. Dieser Link läuft in :count Minuten ab.',
        'ignore' => 'Nicht registriert? Ignorieren Sie diese E-Mail.',
        'cta' => 'E-Mail-Adresse bestätigen',
    ],

    'verify_email_change' => [
        'subject' => 'Neue E-Mail-Adresse bestätigen',
        'preheader' => 'Bestätigen Sie :email, um die Änderung abzuschließen',
        'heading' => ':email bestätigen',
        'body' => 'Sie möchten :email für Ihr Konto bei :company verwenden. Bestätigen Sie die Adresse, um die Änderung abzuschließen. Dieser Link läuft in :count Minuten ab.',
        'ignore' => 'Nicht von Ihnen angefordert? Ignorieren Sie diese E-Mail, und Ihre aktuelle Adresse bleibt bestehen.',
        'cta' => 'Neue E-Mail-Adresse bestätigen',
    ],

    'email_code' => [
        'expires' => 'Dieser Code läuft in :count Minuten ab.',
        'browser_hint' => 'Geben Sie diesen Code in dem Browser-Tab ein, in dem Sie begonnen haben.',
        'latest_only' => 'Nur der zuletzt angeforderte Code funktioniert.',
        'unsolicited' => 'Nicht von Ihnen angefordert? Sie können diese E-Mail ignorieren.',
        'purposes' => [
            'signup' => [
                'subject' => 'Ihr Relaticle-Registrierungscode',
                'preheader' => 'Mit diesem Code schließen Sie die Erstellung Ihres Kontos ab',
                'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
                'body' => 'Geben Sie diesen Code ein, um die Erstellung Ihres Relaticle-Kontos abzuschließen.',
            ],
            'verify_email' => [
                'subject' => 'Ihr Relaticle-Bestätigungscode',
                'preheader' => 'Mit diesem Code bestätigen Sie Ihre E-Mail-Adresse',
                'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
                'body' => 'Geben Sie diesen Code ein, um die E-Mail-Adresse Ihres Relaticle-Kontos zu bestätigen.',
            ],
            'sign_in' => [
                'subject' => 'Ihr Relaticle-Anmeldecode',
                'preheader' => 'Mit diesem Code melden Sie sich an',
                'heading' => 'Bestätigen Sie, dass Sie es sind',
                'body' => 'Geben Sie diesen Code ein, um sich in Ihrem Relaticle-Konto anzumelden.',
            ],
            'confirm_identity' => [
                'subject' => 'Ihr Relaticle-Bestätigungscode',
                'preheader' => 'Mit diesem Code fahren Sie fort',
                'heading' => 'Bestätigen Sie Ihre Identität',
                'body' => 'Geben Sie diesen Code ein, um mit Ihrem Relaticle-Konto fortzufahren.',
            ],
            'change_email' => [
                'subject' => 'Ihr Relaticle-Code zur Änderung der E-Mail-Adresse',
                'preheader' => 'Mit diesem Code bestätigen Sie Ihre neue E-Mail-Adresse',
                'heading' => 'Bestätigen Sie Ihre neue E-Mail-Adresse',
                'body' => 'Geben Sie diesen Code ein, um die Änderung der E-Mail-Adresse Ihres Relaticle-Kontos abzuschließen.',
            ],
            'enable_email_sign_in' => [
                'subject' => 'Ihr Relaticle-Code für die Anmeldung per E-Mail',
                'preheader' => 'Mit diesem Code aktivieren Sie die Anmeldung per E-Mail',
                'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
                'body' => 'Geben Sie diesen Code ein, um die Anmeldung per E-Mail für Ihr Relaticle-Konto zu aktivieren.',
            ],
        ],
    ],

    'email_change_notice' => [
        'subject' => 'Änderung der E-Mail-Adresse angefragt',
        'preheader' => 'Waren Sie das? Falls nicht, blockieren Sie die Änderung',
        'heading' => 'Jemand möchte Ihre E-Mail-Adresse in :email ändern',
        'body' => 'Jemand, der in Ihrem Konto angemeldet ist, möchte die E-Mail-Adresse ändern. Sobald :email bestätigt ist, wird sie zur Adresse Ihres Kontos.',
        'block' => 'Wenn Sie das nicht waren, blockieren Sie die Änderung jetzt, melden Sie sich dann von anderen Sitzungen ab und ändern Sie Ihr Passwort.',
        'cta' => 'Diese Änderung blockieren',
    ],

    'reset_password' => [
        'subject' => 'Passwort zurücksetzen',
        'preheader' => 'Dieser Link läuft in :count Minuten ab',
        'heading' => 'Passwort zurücksetzen',
        'body' => 'Wählen Sie ein neues Passwort für Ihr Relaticle-Konto. Dieser Link läuft in :count Minuten ab.',
        'ignore' => 'Kein Zurücksetzen angefordert? Ignorieren Sie diese E-Mail, und Ihr Passwort bleibt bestehen.',
        'cta' => 'Passwort zurücksetzen',
    ],
];
