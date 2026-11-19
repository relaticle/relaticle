<?php

declare(strict_types=1);

return [
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
            'message' => 'Diese E-Mail ist privat.',
        ],
        'invite_link_expired' => [
            'title' => 'Der Einladungslink ist abgelaufen',
            'body' => 'Der Link wurde entweder abgelehnt, ist abgelaufen oder wurde durch einen neuen Link ersetzt. Fragen Sie den Einladender nach einem neuen Link.',
        ],
        'some_invites_failed' => [
            'title' => 'Einige Einladungen konnten nicht gesendet werden',
            'not_sent_limit' => 'nicht gesendet, das Limit für Einladungen wurde erreicht',
        ],
        'invite_link_not_working' => 'Der Link funktioniert nicht mehr. Bitten Sie die Person, den Einladungslink erneut anzufragen.',
        'workspace_deleting' => 'Dieser Arbeitsbereich wird gelöscht. Sie können ihn vor Ablauf der Frist noch löschen.',
        'workspace_scheduled_for_deletion' => 'Dieser Arbeitsbereich ist zum Löschen am :date geplant.',
    ],

    'modal' => [
        'update_workspace_role' => [
            'description' => ':name (:email)',
        ],
        'leave_workspace' => [
            'notice' => 'Sie verlieren den Zugriff auf diesen Arbeitsbereich. Jede Mailbox, die Sie hier verbunden haben, wird getrennt und ihre E-Mails werden für Ihr Team unsichtbar.',
        ],
        'remove_workspace_member' => [
            'mailbox_notice' => ':name verliert den Zugriff auf diesen Arbeitsbereich. Jede Mailbox, die diese Person verbunden hat, wird getrennt und ihre E-Mails werden für Ihr Team unsichtbar.',
        ],
        'delete_workspace' => [
            'notice' => 'Dieser Arbeitsbereich wird nach einer 30-Tage-Geduldzeit endgültig gelöscht. Sie können die Löschung jederzeit vorher stornieren.',
        ],
        'rotate_invite_link' => [
            'heading' => 'Neuen Einladungslink generieren?',
            'notice' => 'Der aktuelle Link funktioniert nicht mehr. Bitte bitten Sie die Person, den neuen Link zu nutzen.',
        ],
        'disable_invite_link' => [
            'heading' => 'Arbeitsbereich-Link ausschalten?',
            'notice' => 'Niemand kann mehr über diesen Link beitreten. Schalten Sie ihn wieder ein, um neue Einladungen zu versenden.',
        ],
        'invite_link_expired' => [
            'heading' => 'Dieser Einladungslink ist abgelaufen',
            'body' => 'Der Link wurde entweder abgelehnt, ist abgelaufen oder wurde durch einen neuen Link ersetzt. Fragen Sie den Einladender nach einem neuen Link.',
        ],
    ],

    'status' => [
        'none' => 'Kein Status',
        'active' => 'Aktiv',
        'error' => 'Fehler',
        'disconnected' => 'Getrennt',
        'reauth_required' => 'Neu-Authentifizierung erforderlich',
    ],

    'type' => [
        'domain' => 'Domain',
        'user' => 'Benutzer',
    ],
];
