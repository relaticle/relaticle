<?php

declare(strict_types=1);

return [
    'form' => [
        'workspace_name' => [
            'label' => 'Name des Arbeitsbereichs',
        ],
        'workspace_slug' => [
            'label' => 'Slug des Arbeitsbereichs',
            'helper_text' => 'Nur Kleinbuchstaben, Zahlen und Bindestriche.',
        ],
        'emails' => [
            'label' => 'E-Mail-Adressen',
            'placeholder' => 'name@beispiel.de, kollege@beispiel.de',
            'helper' => 'Trennen Sie mehrere Adressen durch Komma, Leerzeichen oder Zeilenumbruch.',
        ],
        'invite_as' => [
            'label' => 'Einladen als',
        ],
        'role' => [
            'label' => 'Rolle',
        ],
        'workspace_logo' => [
            'label' => 'Logo des Arbeitsbereichs',
        ],
        'web_search' => [
            'label' => ':assistant die Websuche erlauben',
            'helper_text' => 'Suchanfragen gehen an die Websuche des KI-Anbieters.',
            'unavailable' => 'Kein Modell dieser Installation kann das Web durchsuchen.',
        ],
    ],

    'sections' => [
        'update_workspace_name' => [
            'title' => 'Name des Arbeitsbereichs',
            'description' => 'Der Name des Arbeitsbereichs und Angaben zum Inhaber.',
        ],
        'update_workspace_logo' => [
            'title' => 'Logo des Arbeitsbereichs',
            'description' => 'Ihr Logo erscheint in der Arbeitsbereich-Auswahl, in Einladungen und auf der Einladungsseite.',
        ],
        'assistant' => [
            'description' => 'Legen Sie fest, was :assistant, der integrierte KI-Assistent, für die Mitglieder dieses Arbeitsbereichs tun darf.',
        ],
        'invite_people' => [
            'description' => 'Jede eingeladene Person erhält eine E-Mail mit einem Link, um :workspace beizutreten.',
        ],
        'add_workspace_member' => [
            'title' => 'Mitglieder einladen',
            'description' => 'Laden Sie Menschen per E-Mail ein. Jede eingeladene Person erhält ihre eigene Einladung.',
        ],
        'workspace_members' => [
            'title' => 'Mitglieder',
            'description' => 'Alle mit Zugriff auf diesen Arbeitsbereich, auch diejenigen, die noch nicht angenommen haben.',
        ],
        'delete_workspace' => [
            'title' => 'Arbeitsbereich löschen',
            'description' => 'Diesen Arbeitsbereich zur Löschung vormerken.',
            'notice' => 'Wenn Sie diesen Arbeitsbereich löschen, wird er nach einer Frist von 30 Tagen endgültig entfernt. Bis dahin können Sie die Löschung jederzeit abbrechen. Nach Ablauf der Frist werden alle Ressourcen und Daten endgültig gelöscht.',
            'scheduled_notice' => 'Dieser Arbeitsbereich ist zur Löschung am :date vorgemerkt.',
        ],
    ],

    'actions' => [
        'save' => 'Speichern',
        'invite_people' => 'Mitglieder einladen',
        'send_invitations' => 'Einladungen senden',
        'close' => 'Schließen',
        'update_workspace_role' => 'Rolle ändern',
        'compare_roles' => 'Rollen vergleichen',
        'compare_roles_help_link' => 'Vollständige Übersicht lesen',
        'remove_workspace_member' => 'Entfernen',
        'leave_workspace' => 'Verlassen',
        'resend_workspace_invitation' => 'Erneut senden',
        'revoke_workspace_invitation' => 'Widerrufen',
        'delete_workspace' => 'Arbeitsbereich löschen',
        'cancel_deletion' => 'Löschung abbrechen',
    ],

    'notifications' => [
        'workspace_deletion_cancelled' => [
            'success' => 'Löschung des Arbeitsbereichs abgebrochen',
        ],
        'workspace_deletion_scheduled' => [
            'success' => 'Arbeitsbereich zur Löschung am :date vorgemerkt',
        ],
        'workspace_invitation_sent' => [
            'success' => 'Einladung gesendet.',
            'success_many' => ':count Einladungen gesendet.',
        ],
        'workspace_invitation_revoked' => [
            'success' => 'Einladung widerrufen.',
        ],
        'workspace_member_removed' => [
            'success' => 'Sie haben dieses Mitglied entfernt.',
        ],
        'leave_workspace' => [
            'success' => 'Sie haben den Arbeitsbereich verlassen.',
        ],
        'permission_denied' => [
            'cannot_promote_to_admin' => 'Nur der Inhaber des Arbeitsbereichs kann den Administratorzugriff erteilen oder entziehen.',
            'cannot_remove_workspace_member' => 'Sie haben keine Berechtigung, dieses Mitglied zu entfernen.',
            'cannot_delete_workspace' => 'Sie haben keine Berechtigung, diesen Arbeitsbereich zu löschen.',
            'cannot_cancel_workspace_deletion' => 'Sie haben keine Berechtigung, die Löschung dieses Arbeitsbereichs abzubrechen.',
        ],
        'role_updated' => [
            'success' => 'Rolle aktualisiert.',
        ],
        'resend_throttled' => 'Bitte warten Sie :seconds Sekunden, bevor Sie erneut senden.',
        'some_invites_failed' => [
            'title' => 'Einige Einladungen konnten nicht gesendet werden',
            'not_sent_limit' => 'nicht gesendet, das Einladungslimit wurde erreicht',
        ],
        'invite_rate_limited' => [
            'title' => 'Zu viele Einladungen gesendet',
            'body' => 'Bitte warten Sie :time, bevor Sie weitere Einladungen senden.',
        ],
    ],

    'validation' => [
        'slug_too_short' => ':attribute muss mindestens 3 Zeichen lang sein.',
        'slug_format' => ':attribute darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.',
        'slug_reserved' => ':attribute ist reserviert und kann nicht verwendet werden.',
        'email_already_invited' => 'Diese Person wurde bereits in den Arbeitsbereich eingeladen.',
        'email_already_member' => 'Dieses Mitglied gehört bereits zum Arbeitsbereich.',
        'only_owner_promotes_admins' => 'Nur der Inhaber des Arbeitsbereichs kann die Administratorrolle erteilen.',
        'no_valid_emails' => 'Geben Sie mindestens eine E-Mail-Adresse ein.',
        'too_many_invites' => 'Sie können höchstens :max Mitglieder auf einmal einladen.',
        'managed_plan_before_deleting' => 'Dieser Arbeitsbereich nutzt einen Tarif, den Relaticle abrechnet. Sprechen Sie uns an, um den Tarif zu beenden, bevor Sie den Arbeitsbereich löschen.',
        'managed_plan_before_deleting_account' => 'Diese Arbeitsbereiche nutzen einen Tarif, den Relaticle abrechnet: :workspaces. Sprechen Sie uns an, um den Tarif zu beenden, bevor Sie Ihr Konto löschen.',
        'remove_members_before_deleting' => 'Entfernen Sie alle Mitglieder aus diesen Arbeitsbereichen oder löschen Sie die Arbeitsbereiche, bevor Sie Ihr Konto löschen: :workspaces',
        'workspace_scheduled_for_deletion' => 'Dieser Arbeitsbereich ist zur Löschung vorgemerkt. Brechen Sie die Löschung ab, um Mitglieder einzuladen.',
    ],

    'modals' => [
        'update_workspace_role' => [
            'description' => ':name (:email)',
        ],
        'leave_workspace' => [
            'notice' => 'Möchten Sie diesen Arbeitsbereich wirklich verlassen?',
            'mailbox_notice' => 'Sie verlieren den Zugriff auf diesen Arbeitsbereich. Jedes hier verbundene Postfach wird getrennt, und seine E-Mails werden für Ihr Team ausgeblendet.',
        ],
        'remove_workspace_member' => [
            'mailbox_notice' => ':name verliert den Zugriff auf diesen Arbeitsbereich. Jedes hier verbundene Postfach wird getrennt, und seine E-Mails werden für alle ausgeblendet.',
        ],
        'delete_workspace' => [
            'notice' => 'Der Arbeitsbereich wird zur Löschung vorgemerkt. Sie haben 30 Tage Zeit, die Löschung abzubrechen, bevor alle Daten endgültig entfernt werden.',
        ],
        'cancel_deletion' => [
            'heading' => 'Löschung des Arbeitsbereichs abbrechen?',
            'notice' => 'Der Arbeitsbereich und alle seine Daten bleiben erhalten.',
        ],
    ],

    'edit_workspace' => 'Einstellungen des Arbeitsbereichs',

    'custom_field_types' => [
        'domain' => 'Domain',
    ],

    'tabs' => [
        'general' => 'Allgemein',
        'members' => 'Mitglieder',
        'custom_fields' => 'Benutzerdefinierte Attribute',
        'email' => 'E-Mail und Kalender',
        'import_history' => 'Importverlauf',
        'activity' => 'Aktivität',
        'billing' => 'Abrechnung',
    ],

    'activity' => [
        'system' => 'System',
        'former_member' => 'Ehemaliges Mitglied',
        'search_placeholder' => 'Nach Eintragsname suchen',
        'record_destroyed' => 'Dieser Eintrag wurde endgültig gelöscht.',
        'yes' => 'Ja',
        'no' => 'Nein',
        'outcome' => [
            'created' => 'hat :subject erstellt',
            'deleted' => 'hat :subject gelöscht',
            'restored' => 'hat :subject wiederhergestellt',
            'other' => 'hat eine Änderung an :subject vorgenommen',
        ],
        'subjects' => [
            'company' => 'ein Unternehmen',
            'people' => 'eine Person',
            'opportunity' => 'eine Verkaufschance',
            'task' => 'eine Aufgabe',
            'note' => 'eine Notiz',
        ],
        'columns' => [
            'created_at' => 'Wann',
            'causer' => 'Wer',
            'source' => 'Quelle',
            'event' => 'Aktion',
            'subject_type' => 'Typ',
            'record' => 'Eintrag',
            'changes' => 'Änderungen',
        ],
        'filters' => [
            'event' => 'Aktion',
            'subject_type' => 'Typ',
            'causer' => 'Wer',
            'source' => 'Quelle',
            'from' => 'Von',
            'until' => 'Bis',
        ],
        'events' => [
            'created' => 'Erstellt',
            'updated' => 'Aktualisiert',
            'deleted' => 'Gelöscht',
            'restored' => 'Wiederhergestellt',
            'imported' => 'Importiert',
            'import_failed' => 'Import fehlgeschlagen',
        ],
        'types' => [
            'company' => 'Unternehmen',
            'people' => 'Person',
            'opportunity' => 'Verkaufschance',
            'task' => 'Aufgabe',
            'note' => 'Notiz',
            'custom_field' => 'Benutzerdefiniertes Attribut',
            'custom_field_option' => 'Option eines benutzerdefinierten Attributs',
            'import' => 'Import',
        ],
        'import_counts' => [
            'created' => ':count erstellt',
            'updated' => ':count aktualisiert',
            'skipped' => ':count übersprungen',
            'failed' => ':count fehlgeschlagen',
        ],
        'via_import' => 'Per Import :file',
        'via_source' => 'Über :source',
        'empty' => [
            'heading' => 'Noch keine Aktivität',
            'description' => 'Änderungen, die Ihre Mitglieder an Einträgen vornehmen, erscheinen hier.',
        ],
        'changes_modal' => [
            'trigger' => 'Alle Änderungen ansehen',
            'trigger_full' => 'Vollständige Änderung ansehen',
            'close' => 'Schließen',
        ],
        'full_change' => [
            'show' => 'Vollständigen Text anzeigen',
            'hide' => 'Vollständigen Text ausblenden',
            'before' => 'Vorher',
            'after' => 'Nachher',
        ],
        'no_results' => [
            'heading' => 'Nichts entspricht diesen Filtern',
            'description' => 'Versuchen Sie einen anderen Suchbegriff oder erweitern Sie den Zeitraum.',
            'action' => 'Filter zurücksetzen',
        ],
    ],

    'roles' => [
        'unknown' => 'Unbekannt',
        'owner' => [
            'label' => 'Inhaber',
        ],
        'admin' => [
            'label' => 'Administrator',
            'description' => 'Verwaltet Mitglieder, benutzerdefinierte Attribute und E-Mail-Einstellungen. Kann Einträge endgültig löschen',
        ],
        'member' => [
            'label' => 'Mitglied',
            'description' => 'Erstellt und bearbeitet Einträge und verschiebt sie in den Papierkorb',
        ],
        'viewer' => [
            'label' => 'Betrachter',
            'description' => 'Liest alles und ändert nichts',
        ],
    ],

    'capabilities' => [
        'records' => [
            'view' => ['label' => 'Einträge ansehen'],
            'create' => ['label' => 'Einträge erstellen'],
            'update' => ['label' => 'Einträge aktualisieren'],
            'delete' => ['label' => 'Einträge löschen und wiederherstellen'],
            'force_delete' => ['label' => 'Einträge endgültig löschen'],
        ],
        'data' => [
            'import' => ['label' => 'Daten importieren'],
            'export' => ['label' => 'Daten exportieren'],
        ],
        'members' => [
            'manage' => ['label' => 'Mitglieder einladen, entfernen und ihre Rollen ändern'],
            'promote_admin' => ['label' => 'Jemanden zum Administrator befördern'],
        ],
        'fields' => [
            'manage' => ['label' => 'Benutzerdefinierte Attribute verwalten'],
        ],
        'billing' => [
            'manage' => ['label' => 'Abrechnung verwalten'],
        ],
        'workspace' => [
            'manage' => ['label' => 'Arbeitsbereich umbenennen oder löschen'],
        ],
        'email' => [
            'manage' => ['label' => 'E-Mail-Einstellungen des Arbeitsbereichs verwalten'],
            'agent_send' => ['label' => 'E-Mails über einen KI-Assistenten senden'],
        ],
        'activity' => [
            'view' => ['label' => 'Aktivitätsprotokoll ansehen'],
        ],
    ],

    'role_matrix' => [
        'capability_column' => 'Berechtigung',
        'granted' => 'Enthalten',
        'not_granted' => 'Nicht enthalten',
        'opens_in_new_tab' => '(öffnet in einem neuen Tab)',
    ],

    'table' => [
        'user' => 'Mitglied',
        'role' => 'Rolle',
        'status' => 'Status',
        'search_placeholder' => 'Nach Name oder E-Mail-Adresse suchen',
        'invite_pending' => 'Einladung ausstehend',
        'invite_expired' => 'Einladung abgelaufen',
        'expires_in' => 'Läuft noch :time',
        'expired_ago' => 'Abgelaufen (:time her)',
        'expired' => 'Abgelaufen',
        'no_results' => [
            'heading' => 'Niemand entspricht dieser Suche',
            'description' => 'Versuchen Sie einen Teil des Namens oder die eingeladene E-Mail-Adresse.',
        ],
    ],

    'invitation' => [
        'joining_as' => 'Beitritt als',
        'members' => '{1} 1 Mitglied ist bereits in diesem Arbeitsbereich|[2,*] :count Mitglieder sind bereits in diesem Arbeitsbereich',
    ],

    'deletion_banner' => [
        'message' => 'Dieser Arbeitsbereich wird am :date endgültig gelöscht.',
    ],

    'pending_for_user' => [
        'heading' => 'Sie wurden eingeladen, :workspace beizutreten',
        'detail_with_inviter' => ':inviter hat Sie mit :role-Zugriff eingeladen.',
        'detail' => 'Sie treten mit :role-Zugriff bei.',
        'accept' => 'Arbeitsbereich beitreten',
        'decline' => 'Ablehnen',
        'declined' => 'Einladung abgelehnt.',
    ],

    'accept' => [
        'joined' => 'Sie sind :workspace beigetreten.',
        'already_member' => 'Sie sind bereits Mitglied von :workspace.',
        'no_longer_valid' => 'Diese Einladung ist nicht mehr gültig. Sie wurde möglicherweise widerrufen oder ist abgelaufen.',
        'email_unverified' => 'Bestätigen Sie Ihre E-Mail-Adresse, bevor Sie einem Arbeitsbereich beitreten.',
        'account_deleting' => 'Sie können keine Einladungen annehmen, solange Ihr Konto zur Löschung vorgemerkt ist.',
        'workspace_deleting' => 'Dieser Arbeitsbereich ist zur Löschung vorgemerkt und nimmt keine neuen Mitglieder auf.',
        'deleting' => [
            'heading' => ':workspace nimmt keine neuen Mitglieder auf',
            'body' => 'Dieser Arbeitsbereich ist zur Löschung vorgemerkt. Bitten Sie die einladende Person, die Löschung abzubrechen, und öffnen Sie die Einladung dann erneut.',
            'action' => 'Zu meinem Arbeitsbereich',
        ],
        'ready' => [
            'heading' => ':workspace beitreten',
            'body_with_inviter' => ':inviter hat Sie eingeladen, :workspace mit :role-Zugriff beizutreten.',
            'body' => 'Sie wurden eingeladen, :workspace mit :role-Zugriff beizutreten.',
            'action' => ':workspace beitreten',
            'decline' => 'Jetzt nicht',
        ],
        'wrong_account' => [
            'heading' => 'Diese Einladung gilt für ein anderes Konto',
            'body' => 'Diese Einladung wurde an :invited gesendet, Sie sind aber als :current angemeldet.',
            'switch' => 'Abmelden und Konto wechseln',
            'stay' => 'Zu meinem Arbeitsbereich',
        ],
        'expired' => [
            'heading' => 'Einladung nicht mehr gültig',
            'body' => 'Diese Einladung ist abgelaufen, wurde bereits verwendet oder durch eine neuere ersetzt.',
            'action' => 'Zu meinem Arbeitsbereich',
        ],
    ],
];
