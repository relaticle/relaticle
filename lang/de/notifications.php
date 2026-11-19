<?php

declare(strict_types=1);

return [
    'title' => 'Benachrichtigungen',

    'throttled' => [
        'title' => 'Zu viele Anfragen',
        'body' => 'Bitte warten Sie :seconds Sekunden, bevor Sie es erneut versuchen.',
    ],

    'digest' => [
        'heading' => 'Tägliche Zusammenfassung',
        'title' => 'Tägliche Zusammenfassung aktivieren',
        'description' => 'Enthält überfällige und heute fällige Aufgaben. Jeder Arbeitsbereich sendet jeden Morgen eine eigene E-Mail, sofern Aufgaben fällig oder überfällig sind.',
    ],

    'collaboration' => [
        'heading' => 'Benachrichtigungen zur Zusammenarbeit',
        'notify_me_about' => 'Mich benachrichtigen über',
    ],

    'channels' => [
        'in_app' => 'App',
        'email' => 'E-Mail',
    ],

    'types' => [
        'task_assigned' => [
            'label' => 'Aufgabenzuweisungen',
            'description' => 'Mich benachrichtigen, wenn mir eine Aufgabe zugewiesen wird.',
        ],
        'email_access' => [
            'label' => 'E-Mail-Zugriff',
            'description' => 'Mir eine E-Mail senden, wenn jemand den Zugriff auf eine meiner E-Mails anfragt. Mich benachrichtigen, wenn meine eigene Anfrage genehmigt, abgelehnt wird oder abläuft.',
        ],
        'email_shared' => [
            'label' => 'Geteilte E-Mails',
            'description' => 'Mich benachrichtigen, wenn jemand eine E-Mail mit mir teilt.',
        ],
        'task_digest' => [
            'label' => 'Tägliche Zusammenfassung',
            'description' => 'Mich jeden Morgen über überfällige und heute fällige Aufgaben benachrichtigen.',
        ],
    ],

    'events' => [
        'task_assigned' => [
            'title' => ':actor hat Ihnen eine Aufgabe zugewiesen',
            'title_without_actor' => 'Sie haben eine neue Aufgabe',
            'action' => 'Aufgabe ansehen',
        ],
    ],

    'panel' => [
        'heading' => 'Posteingang',
        'open' => 'Posteingang öffnen',
        'open_with_count' => 'Posteingang öffnen, :count Element braucht Ihre Aufmerksamkeit|Posteingang öffnen, :count Elemente brauchen Ihre Aufmerksamkeit',
        'close' => 'Posteingang schließen',
        'settings' => 'Benachrichtigungseinstellungen',
        'announce' => ':count neue Benachrichtigung|:count neue Benachrichtigungen',
        'announcements' => [
            'archived' => 'Archiviert: :title',
            'approved' => 'Genehmigt: :title',
            'denied' => 'Abgelehnt: :title',
            'already_decided' => 'Über diese Anfrage wurde bereits entschieden: :title',
        ],
        'footer' => 'Die letzten :count Benachrichtigungen werden angezeigt.',
        'tabs' => [
            'label' => 'Bereiche des Posteingangs',
            'notifications' => 'Benachrichtigungen',
            'requests' => 'Anfragen',
        ],
        'mark_all_read' => 'Alle als gelesen markieren',
        'groups' => [
            'today' => 'Heute',
            'yesterday' => 'Gestern',
            'earlier' => 'Früher',
        ],
        'row' => [
            'unread' => 'Ungelesen',
            'mark_read' => 'Als gelesen markieren',
            'mark_unread' => 'Als ungelesen markieren',
            'archive' => 'Archivieren',
        ],
        'requests' => [
            'view' => 'Ansehen',
            'approve' => 'Genehmigen',
            'deny' => 'Ablehnen',
            'approved' => 'Genehmigt',
            'denied' => 'Abgelehnt',
            'already_decided' => 'Über diese Anfrage wurde bereits entschieden.',
            'expires' => 'Läuft :when ab',
        ],
        'empty' => [
            'notifications' => [
                'heading' => 'Alles erledigt',
                'description' => 'Aufgabenzuweisungen, Importe und Exporte sowie weitere Neuigkeiten aus diesem Arbeitsbereich erscheinen hier.',
            ],
            'requests' => [
                'heading' => 'Keine offenen Anfragen',
                'description' => 'Wenn ein Mitglied etwas anfragt, das Ihnen gehört, entscheiden Sie hier.',
            ],
            'settings' => 'Einstellungen',
        ],
    ],

    'saved' => 'Benachrichtigungseinstellungen aktualisiert.',
];
