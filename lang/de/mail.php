<?php

declare(strict_types=1);

return [
    'fallback_link' => 'Falls die Schaltfläche nicht funktioniert, kopieren Sie diesen Link in Ihren Browser:',

    'footer' => [
        'settings' => 'Benachrichtigungseinstellungen',
        'unsubscribe' => 'Von der täglichen Zusammenfassung abmelden',
        'copyright' => '© :year :company',
        'reason' => [
            'owner' => 'Sie haben diese erhalten, weil Sie den Arbeitsbereich :workspace besitzen.',
            'member' => 'Sie haben diese erhalten, weil Sie Mitglied des Arbeitsbereichs :workspace sind.',
            'former_member' => 'Sie haben diese erhalten, weil Sie früher Mitglied des Arbeitsbereichs :workspace waren.',
            'former_owner' => 'Sie haben diese erhalten, weil Sie den Arbeitsbereich :workspace besessen haben.',
            'digest' => 'Sie haben diese erhalten, weil Sie die tägliche Zusammenfassung aktiviert haben.',
            'assignee' => 'Sie haben diese erhalten, weil eine Aufgabe in :workspace an Sie zugewiesen wurde.',
            'invitee' => 'Sie haben diese erhalten, weil :email zu :workspace eingeladen wurde.',
            'contact' => 'Sie haben diese erhalten, weil jemand das Kontaktformular eingereicht hat.',
            'account' => 'Sie haben diese erhalten wegen einer Anfrage auf Ihrem Konto :company.',
            'onboarding' => 'Sie haben diese erhalten, weil Sie einen Arbeitsbereich :company erstellt haben.',
            'billing_contact' => 'Sie haben diese erhalten, weil Sie der Abrechnungskontakt für :workspace sind.',
        ],
    },

    'unsubscribe' => [
        'title' => 'Abmelden',
        'heading' => 'Die tägliche Zusammenfassung stoppen?',
        'body' => 'Sie erhalten ab jetzt keine morgendlichen Aufgaben-Zusammenfassungen mehr an :email. Sie können es in den Benachrichtigungseinstellungen wieder aktivieren.',
        'confirm' => 'Abmelden',
        'done_heading' => 'Sie sind abgemeldet',
        'done_body' => 'Die tägliche Zusammenfassung ist für :email aus.',
        'settings' => 'Benachrichtigungseinstellungen',
    ],

    'trial_ending' => [
        'subject' => 'Ihr Pro-Versuch endet am :date',
        'preheader' => ':workspace pausiert dann, es sei denn Sie wählen einen Plan. Ihre Aufzeichnungen bleiben sicher.',
        'preheader_grandfathered' => ':workspace kehrt dann zu seinem Cloud Free-Plan zurück. Ihre Aufzeichnungen bleiben sicher.',
        'heading' => 'Pro für :workspace endet am :date',
        'ends_at' => 'Ihr Pro-Versuch endet am :datetime (:zone Zeit).',
        'pauses' => 'Danach pausiert :workspace, bis Sie einen Plan wählen.',
        'kept' => 'Wenn niemand sich 60 Tage danach anmeldet, löschen wir den Arbeitsbereich und seine Aufzeichnungen.',
        'keeps' => 'Pro behält jede KI-Modell, :credits monatliche Credits und höhere Rate-Limits. Ein flacher Preis deckt den gesamten Arbeitsbereich ab.',
        'grandfathered' => 'Wenn Sie nichts tun, kehrt :workspace zu seinem vererbten Cloud Free-Plan zurück. Ihre Daten bleiben unberührt.',
        'cta' => 'Wählen Sie einen Plan',
        'questions' => 'Fragen? Antworten Sie auf diese E-Mail.',
    ],

    'trial_usage' => [
        'so_far' => 'Bisher hat Ihr Team :parts.',
        'during' => 'Während des Versuchs hat Ihr Team :parts.',
        'records' => ':count Aufzeichnung hinzugefügt|:count Aufzeichnungen hinzugefügt',
        'questions' => ':count Frage an Rela gestellt|:count Fragen an Rela gestellt',
        'both' => ':records und :questions',
    ],

    'pro_ended' => [
        'trial_ended' => [
            'subject' => 'Ihr Pro-Versuch für :workspace ist abgelaufen',
            'heading' => 'Ihr Pro-Versuch für :workspace ist abgelaufen',
            'preheader' => 'Ihre Aufzeichnungen sind sicher. Wählen Sie einen Plan oder verlängern Sie den Versuch um :days Tage.',
        ],
        'extension_ended' => [
            'subject' => 'Ihr verlängerter Versuch für :workspace ist abgelaufen',
            'heading' => 'Ihr verlängerter Versuch für :workspace ist abgelaufen',
            'preheader' => 'Ihre Aufzeichnungen sind sicher. Wählen Sie einen Plan, um dort fortzufahren, wo Sie aufgehört haben.',
        ],
        'subscription_ended' => [
            'subject' => 'Ihr Cloud-Abonnement ist abgelaufen',
            'heading' => 'Cloud-Zugang für :workspace ist abgelaufen',
        ],
        'managed_ended' => [
            'subject' => 'Ihr :plan-Plan ist abgelaufen',
            'heading' => ':plan für :workspace ist abgelaufen',
            'preheader' => 'Ihre Aufzeichnungen sind sicher. Sprechen Sie mit uns, um :plan neu zu starten.',
            'grandfathered' => ':workspace ist wieder auf seinem vererbten Cloud Free-Plan. Ihre Daten bleiben unberührt.',
            'restore' => 'Sprechen Sie mit uns, um :plan neu zu starten. Ihre Aufzeichnungen bleiben wie sie sind.',
            'cta' => 'Sprechen Sie mit uns',
            'mailbox_sync_paused' => 'E-Mail und Termin-Synchronisation sind auch pausiert und werden wieder aufgenommen, wenn :plan neu startet.',
        ],
        'trial_paused' => ':workspace ist pausiert. Ihre Aufzeichnungen sind sicher.',
        'kept' => 'Wenn niemand sich anmeldet, löschen wir :workspace am :date. Wählen Sie einen Plan vorher, um es zu behalten.',
        'extend' => 'Benötigen Sie mehr Zeit? [Verlängern Sie Ihren Versuch um :days Tage](:url) von der Abrechnungsseite aus. Dies können Sie einmal tun.',
        'preheader' => 'Ihre Aufzeichnungen sind sicher. Abonnieren Sie, um den Arbeitsbereich wieder zu öffnen.',
        'preheader_grandfathered' => 'Ihr Arbeitsbereich ist wieder auf seinem Cloud Free-Plan.',
        'paused' => 'Cloud-Zugang zu :workspace ist jetzt pausiert. Ihre Aufzeichnungen sind sicher und nichts wurde gelöscht.',
        'mailbox_sync_paused' => 'E-Mail und Termin-Synchronisation sind auch pausiert und werden wieder aufgenommen, sobald Sie abonnieren.',
        'restore' => 'Abonnieren Sie einen Cloud-Plan, um die App, REST API, MCP Server und KI-Assistenten genau dort wieder zu öffnen, wo Sie aufgehört haben.',
        'grandfathered' => ':workspace ist wieder auf seinem vererbten Cloud Free-Plan. Ihre Daten bleiben unberührt und Sie können jederzeit erneut abonnieren.',
        'cta' => 'Wählen Sie einen Plan',
        'cta_grandfathered' => 'Wählen Sie einen Plan',
    ],

    'managed_plan_invoice' => [
        'subject' => 'Ihr :plan-Rechnung von Relaticle',
        'preheader' => 'Ihre jährliche :plan-Rechnung für :workspace ist bereit',
        'heading' => 'Ihre :plan-Rechnung ist bereit',
        'line' => 'Ihre jährliche :plan-Rechnung für :workspace beträgt :amount, fällig am :date. Der Plan schaltet an, wenn er bezahlt wird.',
        'line_without_due' => 'Ihre jährliche :plan-Rechnung für :workspace beträgt :amount. Der Plan schaltet an, wenn er bezahlt wird.',
        'cta' => 'Rechnung anzeigen und bezahlen',
    ],

    'setup_nudge' => [
        'subject' => 'Ihr Arbeitsbereich wartet',
        'preheader' => 'Ein Schritt bringt :workspace in Gang: :step',
        'heading' => ':name, :workspace ist bereit für Ihre eigenen Aufzeichnungen',
        'step' => 'Nächster Schritt: :step.',
        'cta' => 'In :assistant fortfahren',
    ],

    'task_assigned' => [
        'subject' => 'Neue Aufgabe: :title',
        'preheader' => 'An Sie zugewiesen in :workspace',
        'preheader_without_workspace' => 'Eine Aufgabe wurde an Sie zugewiesen',
        'heading' => 'Sie haben eine neue Aufgabe',
        'workspace_label' => 'Arbeitsbereich',
        'cta' => 'Aufgabe anzeigen',
    ],

    'task_digest' => [
        'subject_both' => '[ :workspace] :overdue überfällig, :due heute fällig, :date',
        'subject_overdue' => '[ :workspace] :count überfällige Aufgabe, :date|:workspace] :count überfällige Aufgaben, :date',
        'subject_due' => '[ :workspace] :count Aufgabe heute fällig, :date|:workspace] :count Aufgaben heute fällig, :date',
        'preheader_more' => ':tasks und :count mehr',
        'heading' => 'Guten Morgen, :name',
        'summary' => 'Sie haben :tasks in :workspace.',
        'summary_both' => 'Sie haben :overdue und :due in :workspace.',
        'open_hint' => 'Wählen Sie eine Aufgabe aus, um sie in :workspace zu öffnen.',
        'summary_overdue' => ':count überfällige Aufgabe|:count überfällige Aufgaben',
        'summary_due' => ':count Aufgabe heute fällig|:count Aufgaben heute fällig',
        'overdue' => 'Überfällig',
        'due_today' => 'Heute fällig',
        'due_overdue' => ':date (Überfällig)',
        'due_today_chip' => 'Heute fällig',
        'more_records' => '+:count',
        'more_tasks' => ':count weitere Aufgabe anzeigen|:count weitere Aufgaben anzeigen',
        'cta' => 'Meine Aufgaben öffnen',
    ],

    'workspace_invitation' => [
        'subject' => ':inviter hat Sie zu :workspace eingeladen',
        'subject_without_inviter' => 'Sie wurden zu :workspace eingeladen',
        'preheader' => 'Beitreten Sie :workspace auf Relaticle als :role',
        'heading' => 'Beitreten Sie :workspace',
        'line_with_inviter' => ':inviter hat Sie zum Arbeitsbereich :workspace auf Relaticle mit :role-Zugang eingeladen.',
        'line' => 'Sie wurden zum Arbeitsbereich :workspace auf Relaticle mit :role-Zugang eingeladen.',
        'expiry' => 'Diese Einladung läuft am :expiry ab.',
        'ignore' => 'Erwarten Sie dies nicht? Ignorieren Sie diese E-Mail.',
        'cta' => 'Einladung akzeptieren',
    ],

    'workspace_deletion_scheduled' => [
        'subject' => ':workspace ist zum Löschen geplant',
        'preheader' => 'Löschen am :date. Jederzeit vorher stornieren',
        'heading' => ':workspace wird am :date gelöscht',
        'removes' => 'Personen, Unternehmen, Aufgaben, Gelegenheiten, Notizen und jede andere Aufzeichnung in :workspace werden nach diesem Datum entfernt.',
        'cancel' => 'Sie können jederzeit vorher aus den Arbeitsbereichseinstellungen stornieren.',
        'cta' => 'Löschen stornieren',
    ],

    'workspace_deletion_reminder' => [
        'subject' => ':workspace löscht in :days Tag|:workspace löscht in :days Tagen',
        'preheader' => 'Letzter Hinweis vor :date',
        'heading' => ':days Tag bis :workspace gelöscht wird|:days Tage bis :workspace gelöscht wird',
        'final' => 'Dies ist der letzte Hinweis. Alles in :workspace wird nach :date entfernt.',
        'cancel' => 'Sie können jederzeit vorher aus den Arbeitsbereichseinstellungen stornieren.',
        'cta' => 'Löschen stornieren',
    ],

    'workspace_deletion_cancelled' => [
        'subject' => ':workspace-Löschen storniert',
        'preheader' => 'Ihre Daten sind sicher',
        'heading' => ':workspace bleibt',
        'body' => 'Das geplante Löschen von :workspace wurde storniert. Nichts wurde entfernt.',
        'cta' => ':workspace öffnen',
    ],

    'workspace_member_removed' => [
        'subject' => 'Sie wurden aus :workspace entfernt',
        'preheader' => 'Sie haben keinen Zugang mehr zu diesem Arbeitsbereich',
        'heading' => 'Sie wurden aus :workspace entfernt',
        'body' => 'Ihr Zugang zu :workspace und seinen Aufzeichnungen ist beendet. Ihre anderen Arbeitsbereiche sind unberührt.',
        'cta' => 'Relaticle öffnen',
    ],

    'account_deletion_scheduled' => [
        'subject' => 'Ihr Konto ist zum Löschen geplant',
        'preheader' => 'Löschen am :date. Melden Sie sich an, um zu stornieren',
        'heading' => 'Ihr Konto wird am :date gelöscht',
        'removes' => 'Ihr Profil und jeder Arbeitsbereich, den Sie besitzen, werden nach diesem Datum entfernt.',
        'cancel' => 'Gedanken gewechselt? Melden Sie sich vorher an und das Löschen wird storniert.',
        'cta' => 'Mein Konto behalten',
    ],

    'account_deletion_reminder' => [
        'subject' => 'Ihr Konto löscht in :days Tag|Ihr Konto löscht in :days Tagen',
        'preheader' => 'Letzter Hinweis vor :date',
        'heading' => ':days Tag bis Ihr Konto gelöscht wird|:days Tage bis Ihr Konto gelöscht wird',
        'final' => 'Dies ist der letzte Hinweis. Ihr Konto und seine Daten werden nach :date entfernt.',
        'cancel' => 'Melden Sie sich vorher an und das Löschen wird storniert.',
        'cta' => 'Mein Konto behalten',
    ],

    'account_deletion_cancelled' => [
        'subject' => 'Ihr Konto bleibt',
        'preheader' => 'Löschen storniert, Daten unberührt',
        'heading' => 'Willkommen zurück, :name',
        'body' => 'Das geplante Löschen Ihres Kontos wurde storniert. Nichts wurde entfernt.',
        'cta' => 'Relaticle öffnen',
    ],

    'verify_email' => [
        'subject' => 'Verifizieren Sie Ihre E-Mail',
        'preheader' => 'Ein Klick beendet die Registrierung',
        'heading' => 'Verifizieren Sie Ihre E-Mail-Adresse',
        'body' => 'Bestätigen Sie diese Adresse, um Ihr Relaticle-Konto zu erstellen. Dieser Link läuft in :count Minuten ab.',
        'ignore' => 'Haben Sie sich nicht registriert? Ignorieren Sie diese E-Mail.',
        'cta' => 'E-Mail verifizieren',
    ],

    'verify_email_change' => [
        'subject' => 'Ihre neue E-Mail bestätigen',
        'preheader' => ':email bestätigen, um die Änderung abzuschließen',
        'heading' => ':email bestätigen',
        'body' => 'Sie haben angefordert, :email für Ihr Konto :company zu verwenden. Bestätigen Sie es, um die Änderung abzuschließen. Dieser Link läuft in :count Minuten ab.',
        'ignore' => 'Haben Sie nicht danach gefragt? Ignorieren Sie diese E-Mail und Ihre aktuelle Adresse bleibt.',
        'cta' => 'Neue E-Mail bestätigen',
    ],

    'email_code' => [
        'expires' => 'Dieser Code läuft in :count Minuten ab.',
        'browser_hint' => 'Geben Sie diesen Code im Browser-Tab ein, wo Sie begonnen haben.',
        'latest_only' => 'Nur der neueste Code, den Sie angefordert haben, funktioniert.',
        'unsolicited' => 'Haben Sie dies nicht angefordert? Sie können diese E-Mail ignorieren.',
        'purposes' => [
            'signup' => [
                'subject' => 'Ihr Relaticle-Registrierungscode',
                'preheader' => 'Verwenden Sie diesen Code, um Ihr Konto zu erstellen',
                'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
                'body' => 'Geben Sie diesen Code ein, um Ihr Relaticle-Konto zu erstellen.',
            ],
            'verify_email' => [
                'subject' => 'Ihr Relaticle-Verifizierungscode',
                'preheader' => 'Verwenden Sie diesen Code, um Ihre E-Mail zu verifizieren',
                'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
                'body' => 'Geben Sie diesen Code ein, um Ihr Relaticle-Konto-E-Mail zu verifizieren.',
            ],
            'sign_in' => [
                'subject' => 'Ihr Relaticle-Anmeldecode',
                'preheader' => 'Verwenden Sie diesen Code, um sich anzumelden',
                'heading' => "Bestätigen Sie, dass es Sie sind",
                'body' => 'Geben Sie diesen Code ein, um sich bei Ihrem Relaticle-Konto anzumelden.',
            ],
            'confirm_identity' => [
                'subject' => 'Ihr Relaticle-Bestätigungscode',
                'preheader' => 'Verwenden Sie diesen Code, um fortzufahren',
                'heading' => 'Bestätigen Sie Ihre Identität',
                'body' => 'Geben Sie diesen Code ein, um mit Ihrem Relaticle-Konto fortzufahren.',
            ],
            'change_email' => [
                'subject' => 'Ihr Relaticle-E-Mail-Änderungscode',
                'preheader' => 'Verwenden Sie diesen Code, um Ihre neue E-Mail zu bestätigen',
                'heading' => 'Bestätigen Sie Ihre neue E-Mail',
                'body' => 'Geben Sie diesen Code ein, um die E-Mail Ihres Relaticle-Kontos zu ändern.',
            ],
            'enable_email_sign_in' => [
                'subject' => 'Ihr Relaticle-E-Mail-Anmeldecode',
                'preheader' => 'Verwenden Sie diesen Code, um E-Mail-Anmeldung einzuschalten',
                'heading' => 'Bestätigen Sie Ihre E-Mail-Adresse',
                'body' => 'Geben Sie diesen Code ein, um die E-Mail-Anmeldung für Ihr Relaticle-Konto einzuschalten.',
            ],
        ],
    ],

    'email_change_notice' => [
        'subject' => 'E-Mail-Änderung angefordert',
        'preheader' => 'War das Sie? Blockieren Sie es, falls nicht',
        'heading' => 'Jemand hat Ihre E-Mail zu :email ändern wollen',
        'body' => 'Jemand meldete sich bei Ihrem Konto an und wollte seine E-Mail ändern. Sobald :email bestätigt wird, wird es die Adresse auf Ihrem Konto.',
        'block' => 'Falls dies nicht Sie waren, blockieren Sie die Änderung jetzt, melden Sie sich dann aus anderen Sitzungen aus und ändern Sie Ihr Passwort.',
        'cta' => 'Diese Änderung blockieren',
    ],

    'reset_password' => [
        'subject' => 'Ihr Passwort zurücksetzen',
        'preheader' => 'Dieser Link läuft in :count Minuten ab',
        'heading' => 'Ihr Passwort zurücksetzen',
        'body' => 'Wählen Sie ein neues Passwort für Ihr Relaticle-Konto. Dieser Link läuft in :count Minuten ab.',
        'ignore' => 'Haben Sie keine Zurücksetzung angefordert? Ignorieren Sie diese E-Mail und Ihr Passwort bleibt.',
        'cta' => 'Passwort zurücksetzen',
    ],
];