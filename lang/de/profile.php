<?php

declare(strict_types=1);

return [
    'form' => [
        'name' => [
            'label' => 'Name',
        ],
        'email' => [
            'label' => 'E-Mail',
        ],
        'profile_photo' => [
            'label' => 'Foto',
        ],
        'timezone' => [
            'label' => 'Zeitzone',
            'helper_text' => 'Datums- und Zeitangaben in der gesamten App werden in dieser Zeitzone angezeigt.',
            'placeholder' => 'Zeitzone auswählen',
        ],
        'locale' => [
            'label' => 'Sprache',
            'helper_text' => 'Die App, E-Mails und Benachrichtigungen verwenden diese Sprache.',
            'placeholder' => 'Sprache auswählen',
        ],
        'current_password' => [
            'label' => 'Aktuelles Passwort',
        ],
        'new_password' => [
            'label' => 'Neues Passwort',
        ],
        'confirm_password' => [
            'label' => 'Passwort bestätigen',
        ],
        'password' => [
            'label' => 'Passwort',
            'throttled' => 'Zu viele Versuche. Bitte versuchen Sie es in :seconds Sekunden erneut.',
        ],
    ],

    'sections' => [
        'update_profile_information' => [
            'title' => 'Profilinformationen',
            'description' => 'Aktualisieren Sie die Profilinformationen und die E-Mail-Adresse Ihres Kontos.',
        ],
        'update_password' => [
            'title' => 'Passwort ändern',
            'description' => 'Verwenden Sie ein langes, zufälliges Passwort, damit Ihr Konto sicher bleibt.',
        ],
        'set_password' => [
            'title' => 'Passwort festlegen',
            'description' => 'Fügen Sie Ihrem Konto ein Passwort hinzu, damit Sie sich auch mit E-Mail-Adresse und Passwort anmelden können.',
        ],
        'browser_sessions' => [
            'title' => 'Browsersitzungen',
            'description' => 'Verwalten Sie Ihre aktiven Sitzungen in anderen Browsern und auf anderen Geräten und melden Sie sie ab.',
            'notice' => 'Bei Bedarf können Sie sich von allen anderen Browsersitzungen auf allen Ihren Geräten abmelden. Einige Ihrer letzten Sitzungen sind unten aufgeführt, die Liste ist jedoch möglicherweise nicht vollständig. Wenn Sie vermuten, dass Ihr Konto kompromittiert wurde, sollten Sie außerdem Ihr Passwort ändern.',
            'labels' => [
                'current_device' => 'Dieses Gerät',
                'last_active' => 'Zuletzt aktiv',
                'unknown_device' => 'Unbekannt',
            ],
        ],
        'delete_account' => [
            'title' => 'Konto löschen',
            'description' => 'Löschen Sie Ihr Konto nach einer Frist von 30 Tagen endgültig.',
            'notice' => 'Ihr Profil und Ihr Anmeldekonto werden nach 30 Tagen endgültig gelöscht. Arbeitsbereiche, denen nur Sie angehören, werden samt ihren CRM-Daten ebenfalls gelöscht. Einträge in gemeinsamen Arbeitsbereichen bleiben ohne Ihr Profil erhalten. Melden Sie sich vor dem Löschdatum an, um den Vorgang abzubrechen.',
            'confirm_email_label' => 'Geben Sie zur Bestätigung die E-Mail-Adresse Ihres Kontos ein',
            'confirm_email_mismatch' => 'Das stimmt nicht mit der E-Mail-Adresse Ihres Kontos überein.',
        ],
        'mfa' => [
            'title' => 'Zwei-Faktor-Authentifizierung',
            'description' => 'Verlangen Sie einen Authenticator-Code, wenn Sie sich ohne Passkey anmelden.',
            'status_enabled' => 'Die Zwei-Faktor-Authentifizierung ist aktiviert.',
            'status_disabled' => 'Die Zwei-Faktor-Authentifizierung ist deaktiviert. Aktivieren Sie sie, um Ihr Konto zu schützen, falls Ihr Passwort einmal gestohlen wird.',
            'enable' => 'Aktivieren',
            'disable' => 'Deaktivieren',
            'identity_description' => 'Bestätigen Sie Ihre Identität, bevor Sie Ihre Authenticator-App einrichten.',
            'continue' => 'Weiter',
            'setup_heading' => 'Authenticator-App einrichten',
            'verify' => 'Bestätigen und aktivieren',
            'recovery_save_heading' => 'Wiederherstellungscodes speichern',
            'recovery_saved' => 'Ich habe meine Wiederherstellungscodes gespeichert',
            'enable_heading' => 'Zwei-Faktor-Authentifizierung aktivieren',
            'enable_description' => 'Scannen Sie den Code mit Ihrer Authenticator-App und geben Sie dann den sechsstelligen Code ein, den die App anzeigt.',
            'disable_heading' => 'Zwei-Faktor-Authentifizierung deaktivieren',
            'disable_description' => 'Bei der Anmeldung werden Sie nicht mehr nach einem Code gefragt.',
            'scan_hint' => 'Scannen Sie diesen Code mit Ihrer Authenticator-App.',
            'manual_hint' => 'Scannen nicht möglich? Geben Sie stattdessen diesen Schlüssel ein:',
            'code_label' => 'Sechsstelliger Code',
            'code_invalid' => 'Dieser Code ist nicht korrekt. Prüfen Sie Ihre Authenticator-App und versuchen Sie es erneut.',
            'confirm' => 'Bestätigen',
            'enabled_notification' => 'Die Zwei-Faktor-Authentifizierung ist aktiviert.',
            'disabled_notification' => 'Die Zwei-Faktor-Authentifizierung ist deaktiviert.',
            'recovery_title' => 'Wiederherstellungscodes',
            'recovery_description' => 'Bewahren Sie diese Codes sicher auf. Jeder Code meldet Sie einmal an, falls Sie Ihre Authenticator-App verlieren.',
            'recovery_show' => 'Wiederherstellungscodes anzeigen',
            'recovery_regenerate' => 'Neue Codes erzeugen',
            'recovery_regenerated' => 'Neue Wiederherstellungscodes erzeugt. Die alten funktionieren nicht mehr.',
            'recovery_heading' => 'Ihre Wiederherstellungscodes',
            'copy' => 'Kopieren',
            'copied' => 'Kopiert',
            'copy_key' => 'Einrichtungsschlüssel kopieren',
            'copy_codes' => 'Alle Codes kopieren',
        ],

        'passkeys' => [
            'title' => 'Passkeys',
            'description' => 'Verwalten Sie Ihre Passkeys für die Anmeldung ohne Passwort.',
            'unsupported' => 'Passkeys werden in diesem Browser nicht unterstützt.',
            'empty' => 'Noch keine Passkeys. Fügen Sie einen hinzu, um sich ohne Passwort anzumelden.',
            'added' => 'Hinzugefügt :time',
            'last_used' => 'Zuletzt verwendet :time',
            'add_passkey' => 'Passkey hinzufügen',
            'add_description' => 'Registrieren Sie einen neuen Passkey für dieses Gerät, um sich ohne Passwort anzumelden.',
            'name_label' => 'Name des Passkeys',
            'name_placeholder' => 'z. B. MacBook Pro, iPhone',
            'default_name' => 'Passkey',
            'rename' => 'Umbenennen',
            'save' => 'Speichern',
            'use_password' => 'Stattdessen Passwort verwenden',
            'method_hint' => 'Sie bestätigen mit Face ID, Touch ID oder Ihrem Passkey.',
            'confirmed' => 'Bestätigt',
            'register' => 'Passkey registrieren',
            'registering' => 'Registrierung läuft…',
            'waiting' => 'Warten auf Passkey …',
            'cancel' => 'Abbrechen',
            'remove' => 'Entfernen',
            'remove_confirm_title' => 'Passkey entfernen',
            'remove_confirm' => 'Sie können ihn danach nicht mehr zur Anmeldung verwenden.',
        ],
    ],

    'actions' => [
        'save' => 'Speichern',
        'remove_photo' => 'Foto entfernen',
        'delete_account' => 'Konto löschen',
        'log_out_other_browsers' => 'Andere Browsersitzungen abmelden',
    ],

    'notifications' => [
        'account_deletion_cancelled' => 'Kontolöschung abgebrochen',
        'save' => [
            'success' => 'Gespeichert.',
        ],
        'photo_removed' => 'Profilfoto entfernt.',
        'photo_remove_failed' => 'Ihr Profilfoto konnte nicht entfernt werden. Bitte versuchen Sie es erneut.',
        'logged_out_other_sessions' => [
            'success' => 'Alle anderen Browsersitzungen wurden erfolgreich abgemeldet.',
            'remembered_only' => 'Andere Geräte werden abgemeldet, sobald sie :minutes Minuten inaktiv sind. Ein Gerät, auf dem Relaticle geöffnet ist, bleibt angemeldet. Ändern Sie Ihr Passwort, um es sofort abzumelden.',
        ],
        'delete_account_blocked' => [
            'title' => 'Kontolöschung blockiert',
        ],
        'passkey_removed' => [
            'success' => 'Passkey entfernt.',
        ],
        'passkey_renamed' => [
            'success' => 'Passkey umbenannt.',
        ],
        'passkey_registration_failed' => [
            'title' => 'Der Passkey konnte nicht hinzugefügt werden. Bitte versuchen Sie es erneut.',
        ],
        'passkey_confirmation_failed' => [
            'title' => 'Die Passkey-Überprüfung ist fehlgeschlagen. Bitte versuchen Sie es erneut.',
        ],
        'identity_confirmation_failed' => [
            'title' => 'Die Identitätsbestätigung ist fehlgeschlagen. Bitte versuchen Sie es erneut.',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Ihr Profil und Ihr Anmeldekonto werden nach 30 Tagen gelöscht. Arbeitsbereiche, denen nur Sie angehören, werden ebenfalls gelöscht. Einträge in gemeinsamen Arbeitsbereichen bleiben erhalten. Melden Sie sich vor dem Löschdatum an, um den Vorgang abzubrechen.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Andere Browsersitzungen abmelden',
            'description' => 'Sie werden auf allen Ihren anderen Geräten abgemeldet.',
        ],
    ],

    'security' => 'Sicherheit',

    'scheduled_deletion_interstitial' => [
        'heading' => 'Ihr Konto ist zur Löschung vorgemerkt',
        'details' => [
            'account' => 'Ihr Profil und Ihr Anmeldekonto werden endgültig gelöscht.',
            'deletion_date' => 'Löschdatum:',
            'workspaces' => ':count Arbeitsbereich, dessen alleiniger Inhaber Sie sind, wird samt CRM-Daten ebenfalls gelöscht.|:count Arbeitsbereiche, deren alleiniger Inhaber Sie sind, werden samt CRM-Daten ebenfalls gelöscht.',
            'shared_records' => 'Einträge in gemeinsamen Arbeitsbereichen bleiben ohne Ihr Profil erhalten.',
        ],
        'help' => 'Anderer Meinung? Behalten Sie Ihr Konto, um die Löschung abzubrechen und den Zugriff wiederherzustellen.',
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Mein Konto behalten',
                'modal_heading' => 'Konto behalten?',
                'modal_description' => 'Die geplante Löschung wird abgebrochen. Sie erhalten wieder Zugriff auf Ihr Konto und Ihre Arbeitsbereiche.',
                'modal_submit_label' => 'Ja, mein Konto behalten',
            ],
            'logout' => [
                'label' => 'Abmelden',
            ],
        ],
    ],
];
