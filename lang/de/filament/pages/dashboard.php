<?php

declare(strict_types=1);

return [
    'title' => 'Dashboard',
    'navigation_label' => 'Dashboard',
    'subheading' => 'Aktualisieren Sie Ihre Kontoberechtigungen und -einstellungen.',
    'tabs' => [
        'general' => 'Allgemein',
        'members' => 'Mitglieder',
        'custom_fields' => 'Benutzerdefinierte Felder',
        'email' => 'E-Mail',
        'import_history' => 'Import-Verlauf',
        'activity' => 'Aktivitäten',
        'billing' => 'Abrechnung',
    ],
    'actions' => [
        'save' => 'Speichern',
        'invite_people' => 'Arbeitsbereichsmitglieder einladen',
        'send_invitations' => 'Einladungen senden',
        'invite_link' => 'Einladungslink',
        'close' => 'Schließen',
        'copy_invite_link' => 'Link kopieren',
        'rotate_invite_link' => 'Neuen Link generieren',
        'disable_invite_link' => 'Link ausschalten',
        'update_workspace_role' => 'Rolle ändern',
        'compare_roles' => 'Rollen vergleichen',
        'compare_roles_help_link' => 'Lesen Sie die vollständige Aufschlüsselung',
        'remove_workspace_member' => 'Entfernen',
        'leave_workspace' => 'Verlassen',
        'resend_workspace_invitation' => 'Einladung erneut senden',
        'cancel_deletion' => 'Löschen stornieren',
    ],
    'notifications' => [
        'workspace_invitation_sent' => [
            'success' => 'Einladung gesendet.',
            'success_many' => ':count Einladungen gesendet.',
        ],
        'workspace_invitation_revoked' => [
            'success' => 'Einladung revokiert.',
        ],
        'leave_workspace' => [
            'success' => 'Sie haben den Arbeitsbereich verlassen.',
        ],
        'permission_denied' => [
            'cannot_promote_to_admin' => 'Nur der Arbeitsbereichsbesitzer kann den Admin-Status gewähren.',
            'cannot_remove_workspace_member' => 'Sie haben nicht die Berechtigung, dieses Mitglied zu entfernen.',
            'cannot_delete_workspace' => 'Sie haben nicht die Berechtigung, diesen Arbeitsbereich zu löschen.',
            'ignore' => 'Ignorieren',
            'message' => 'Dieses Mitglied ist für Sie nicht sichtbar.',
        ],
        'invite_link_expired' => [
            'title' => 'Der Einladungslink ist abgelaufen',
            'body' => 'Der Link wurde entweder abgelehnt, ist abgelaufen oder wurde durch einen neuen Link ersetzt. Fragen Sie den Einladender nach einem neuen Link.',
            'action' => 'Gehen Sie zu meinem Arbeitsbereich',
        ],
        'some_invites_failed' => [
            'title' => 'Einige Einladungen konnten nicht gesendet werden',
            'not_sent_limit' => 'Nicht gesendet, das Limit wurde erreicht',
        ],
    ],
    'sections' => [
        'update_workspace_name' => [
            'title' => 'Arbeitsbereichsname',
            'description' => 'Der Name und die Besitzerinformationen des Arbeitsbereichs.',
        ],
        'update_workspace_logo' => [
            'title' => 'Arbeitsbereichslogo',
            'description' => 'Ihr Logo erscheint im Arbeitsbereichschalter, auf Einladungen und auf der Beitreten-Seite.',
        ],
        'assistant' => [
            'description' => 'Wählen Sie aus, was :assistant, der eingebaute KI-Assistent, für Mitglieder dieses Arbeitsbereichs tun darf.',
        ],
        'invite_people' => [
            'description' => 'Senden Sie eine E-Mail-Einladung oder teilen Sie einen Link, der es Menschen ermöglicht, sich selbst zu verbinden.',
        ],
        'workspace_members' => [
            'title' => 'Mitglieder',
            'description' => 'Jeder mit Zugang zu diesem Arbeitsbereich, einschließlich Personen, die noch nicht akzeptiert haben.',
        ],
        'delete_workspace' => [
            'title' => 'Arbeitsbereich löschen',
            'description' => 'Planen Sie diesen Arbeitsbereich zur endgültigen Entfernung nach einer 30-Tage-Geduldzeitdauer. Sie können die Löschung jederzeit vorher stornieren. Nach der Geduldzeit werden alle Ressourcen und Daten endgültig gelöscht.',
            'scheduled_notice' => 'Dieser Arbeitsbereich ist zum Löschen am :date geplant.',
        ],
    ],
    'form' => [
        'workspace_name' => [
            'label' => 'Arbeitsbereichsname',
        ],
        'workspace_slug' => [
            'label' => 'Arbeitsbereich-Slug',
            'helper_text' => 'Nur Kleinbuchstaben, Zahlen und Bindestriche.',
        ],
        'emails' => [
            'label' => 'E-Mail-Adressen',
            'placeholder' => 'name@company.com, teammate@company.com',
            'helper' => 'Trennen Sie mehrere Adressen durch ein Komma, ein Leerzeichen oder eine neue Zeile.',
        ],
        'invite_as' => [
            'label' => 'Einladen als',
        ],
        'role' => [
            'label' => 'Rolle',
        ],
        'workspace_logo' => [
            'label' => 'Arbeitsbereichslogo',
        ],
        'web_search' => [
            'label' => ':assistant das Web suchen lassen',
            'helper_text' => 'Suchanfragen gehen zur Websuche des KI-Anbieters.',
            'unavailable' => 'Kein Modell auf dieser Installation kann das Web suchen.',
        ],
    ],
    'actions' => [
        'save' => 'Speichern',
        'invite_people' => 'Arbeitsbereichsmitglieder einladen',
        'send_invitations' => 'Einladungen senden',
        'invite_link' => 'Einladungslink',
        'close' => 'Schließen',
        'copy_invite_link' => 'Link kopieren',
        'rotate_invite_link' => 'Neuen Link generieren',
        'disable_invite_link' => 'Link ausschalten',
        'enable_invite_link' => 'Link einschalten',
        'update_workspace_role' => 'Rolle ändern',
        'compare_roles' => 'Rollen vergleichen',
        'compare_roles_help_link' => 'Lesen Sie die vollständige Aufschlüsselung',
        'remove_workspace_member' => 'Entfernen',
        'leave_workspace' => 'Verlassen',
        'resend_workspace_invitation' => 'Einladung erneut senden',
        'cancel_deletion' => 'Löschen stornieren',
    ],
];
<EOF>