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
            'helper_text' => 'Daten und Zeiten in der App werden in dieser Zeitzone angezeigt.',
            'placeholder' => 'Eine Zeitzone auswählen',
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
            'title' => 'Passwort aktualisieren',
            'description' => 'Stellen Sie sicher, dass Ihr Konto ein langes, zufälliges Passwort verwendet, um sicher zu bleiben.',
        ],
        'set_password' => [
            'title' => 'Passwort festlegen',
            'description' => 'Fügen Sie ein Passwort zu Ihrem Konto hinzu, damit Sie sich auch mit E-Mail und Passwort anmelden können.',
        ],
        'browser_sessions' => [
            'title' => 'Browser-Sitzungen',
            'description' => 'Verwalten und melden Sie sich aus Ihren aktiven Sitzungen auf anderen Browsern und Geräten ab.',
            'notice' => 'Falls erforderlich, können Sie sich von allen Ihren anderen Browser-Sitzungen über alle Ihre Geräte abmelden. Einige Ihrer neuesten Sitzungen sind unten aufgelistet; diese Liste ist jedoch möglicherweise nicht vollständig. Wenn Sie das Gefühl haben, Ihr Konto wurde kompromittiert, sollten Sie auch Ihr Passwort aktualisieren.',
            'labels' => [
                'current_device' => 'Dieses Gerät',
                'last_active' => 'Letztes Aktiv',
                'unknown_device' => 'Unbekannt',
            ],
        ],
        'delete_account' => [
            'title' => 'Konto löschen',
            'description' => 'Löschen Sie Ihr Konto nach einer 30-Tage-Geduldzeitdauer.',
            'notice' => 'Ihr Profil und das Anmeldekonto werden nach 30 Tagen endgültig gelöscht. Arbeitsbereiche, zu denen nur Sie gehören, einschließlich ihrer CRM-Daten, werden ebenfalls gelöscht. Aufzeichnungen in geteilten Arbeitsbereichen bleiben ohne Ihr Profil erhalten. Melden Sie sich vor dem Löschungsdatum an, um zu stornieren.',
            'confirm_email_label' => 'Geben Sie Ihre Kontoe-Mail ein, um zu bestätigen',
            'confirm_email_mismatch' => 'Das stimmt nicht mit Ihrer Kontoe-Mail überein.',
        ],
        'mfa' => [
            'title' => 'Zwei-Faktor-Authentifizierung',
            'description' => 'Erfordern Sie einen Authentifizierungscode, wenn Sie sich ohne Passkey anmelden.',
            'status_enabled' => 'Zwei-Faktor-Authentifizierung ist an.',
            'status_disabled' => 'Zwei-Faktor-Authentifizierung ist aus. Schalten Sie sie an, um Ihr Konto zu schützen, falls Ihr Passwort jemals gestohlen wird.',
            'enable' => 'Anschalten',
            'disable' => 'Ausschalten',
            'identity_description' => 'Bestätigen Sie Ihre Identität, bevor Sie Ihre Authentifizierungsapp einrichten.',
            'continue' => 'Weiter',
            'setup_heading' => 'Richten Sie Ihre Authentifizierungsapp ein',
            'verify' => 'Verifizieren und anschalten',
            'recovery_save_heading' => 'Speichern Sie Ihre Wiederherstellungscodes',
            'recovery_saved' => 'Ich habe meine Wiederherstellungscodes gespeichert',
            'enable_heading' => 'Zwei-Faktor-Authentifizierung einschalten',
            'enable_description' => 'Scannen Sie den Code mit Ihrer Authentifizierungsapp, geben Sie dann den sechsstelligen Code ein, den sie zeigt.',
            'disable_heading' => 'Zwei-Faktor-Authentifizierung ausschalten',
            'disable_description' => 'Sie werden nicht mehr nach einem Code gefragt, wenn Sie sich anmelden.',
            'scan_hint' => 'Scannen Sie dies mit Ihrer Authentifizierungsapp.',
            'manual_hint' => 'Kann nicht scannen? Geben Sie diesen Schlüssel stattdessen ein:',
            'code_label' => 'Sechsstelliger Code',
            'code_invalid' => 'Dieser Code ist nicht richtig. Überprüfen Sie Ihre Authentifizierungsapp und versuchen Sie es erneut.',
            'confirm' => 'Bestätigen',
            'enabled_notification' => 'Zwei-Faktor-Authentifizierung ist an.',
            'disabled_notification' => 'Zwei-Faktor-Authentifizierung ist aus.',
            'recovery_title' => 'Wiederherstellungscodes',
            'recovery_description' => 'Speichern Sie diese irgendwo sicher. Jeder von ihnen meldet Sie einmal an, wenn Sie Ihre Authentifizierungsapp verlieren.',
            'recovery_show' => 'Wiederherstellungscodes anzeigen',
            'recovery_regenerate' => 'Neue Codes generieren',
            'recovery_regenerated' => 'Neue Wiederherstellungscodes generiert. Die alten funktionieren nicht mehr.',
            'recovery_heading' => 'Ihre Wiederherstellungscodes',
            'copy' => 'Kopieren',
            'copied' => 'Kopiert',
            'copy_key' => 'Einrichtungsschlüssel kopieren',
            'copy_codes' => 'Alle Codes kopieren',
        ],

        'passkeys' => [
            'title' => 'Passkeys',
            'description' => 'Verwalten Sie Ihre Passkeys für passwortlose Anmeldung.',
            'unsupported' => 'Passkeys werden in diesem Browser nicht unterstützt.',
            'empty' => 'Noch keine Passkeys. Fügen Sie einen hinzu, um sich ohne Passwort anzumelden.',
            'added' => 'Hinzugefügt :time',
            'last_used' => 'Letzter Gebrauch :time',
            'add_passkey' => 'Passkey hinzufügen',
            'add_description' => 'Registrieren Sie einen neuen Passkey für dieses Gerät, um sich ohne Passwort anzumelden.',
            'name_label' => 'Passkey-Name',
            'name_placeholder' => 'z.B. MacBook Pro, iPhone',
            'default_name' => 'Passkey',
            'rename' => 'Umbenennen',
            'save' => 'Speichern',
            'use_password' => 'Stattdessen Ihr Passwort verwenden',
            'method_hint' => "Sie bestätigen mit Face ID, Touch ID oder Ihrem Passkey.",
            'confirmed' => 'Bestätigt',
            'register' => 'Passkey registrieren',
            'registering' => 'Registrieren...',
            'waiting' => 'Warten auf Passkey…',
            'cancel' => 'Abbrechen',
            'remove' => 'Entfernen',
            'remove_confirm_title' => 'Passkey entfernen',
            'remove_confirm' => 'Sie können es nicht mehr verwenden, um sich anzumelden.',
        ],
    ],

    'actions' => [
        'save' => 'Speichern',
        'remove_photo' => 'Foto entfernen',
        'delete_account' => 'Konto löschen',
        'log_out_other_browsers' => 'Andere Browser-Sitzungen abmelden',
    ],

    'notifications' => [
        'save' => [
            'success' => 'Gespeichert.',
        ],
        'photo_removed' => 'Profilfoto entfernt.',
        'photo_remove_failed' => 'Ihr Profilfoto konnte nicht entfernt werden. Bitte versuchen Sie es erneut.',
        'logged_out_other_sessions' => [
            'success' => 'Alle anderen Browser-Sitzungen wurden erfolgreich abgemeldet.',
            'remembered_only' => 'Andere Geräte werden abgemeldet, sobald sie inaktiv sind :minutes Minuten. Ein Gerät mit Relaticle offen bleibt angemeldet. Ändern Sie Ihr Passwort, um es auf einmal abzumelden.',
        ],
        'delete_account_blocked' => [
            'title' => 'Kontolöschen blockiert',
        ],
        'passkey_removed' => [
            'success' => 'Passkey entfernt.',
        ],
        'passkey_renamed' => [
            'success' => 'Passkey umbenannt.',
        ],
        'passkey_registration_failed' => [
            'title' => 'Passkey konnte nicht hinzugefügt werden. Bitte versuchen Sie es erneut.',
        ],
        'passkey_confirmation_failed' => [
            'title' => 'Passkey-Verifizierung fehlgeschlagen. Bitte versuchen Sie es erneut.',
        ],
        'identity_confirmation_failed' => [
            'title' => 'Identitätsbestätigung fehlgeschlagen. Bitte versuchen Sie es erneut.',
        ],
    ],

    'modals' => [
        'delete_account' => [
            'notice' => 'Ihr Profil und das Anmeldekonto werden nach 30 Tagen gelöscht. Arbeitsbereiche, zu denen nur Sie gehören, werden ebenfalls gelöscht. Geteilte Arbeitsbereichsaufzeichnungen bleiben erhalten. Melden Sie sich vor dem Löschungsdatum an, um zu stornieren.',
        ],
        'log_out_other_browsers' => [
            'title' => 'Andere Browser-Sitzungen abmelden',
            'description' => 'Sie werden auf allen Ihren anderen Geräten abgemeldet.',
        ],
    ],

    'security' => 'Sicherheit',

    'edit_profile' => 'Profil bearbeiten',

    'scheduled_deletion_interstitial' => [
        'heading' => 'Ihr Konto ist zum Löschen geplant',
        'details' => [
            'account' => 'Ihr Profil und das Anmeldekonto werden endgültig gelöscht.',
            'deletion_date' => 'Löschungsdatum:',
            'workspaces' => ':count allein besitzter Arbeitsbereich und seine CRM-Daten werden ebenfalls gelöscht.|:count allein besitzte Arbeitsbereiche und ihre CRM-Daten werden ebenfalls gelöscht.',
            'shared_records' => 'Aufzeichnungen in geteilten Arbeitsbereichen bleiben ohne Ihr Profil erhalten.',
        ],
        'help' => 'Gedanken gewechselt? Behalten Sie Ihr Konto, um diese Löschung zu stornieren und den Zugang wiederherzustellen.',
        'actions' => [
            'cancel_deletion' => [
                'label' => 'Mein Konto behalten',
                'modal_heading' => 'Ihr Konto behalten?',
                'modal_description' => 'Ihre geplante Löschung wird storniert. Sie werden den Zugang zu Ihrem Konto und Arbeitsbereichen wiedererlangen.',
                'modal_submit_label' => 'Ja, mein Konto behalten',
            ],
            'logout' => [
                'label' => 'Abmelden',
            ],
        ],
    ],
];