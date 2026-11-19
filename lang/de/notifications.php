<?php

declare(strict_types=1);

return [
    'title' => 'Benachrichtigungen',

    'digest' => [
        'heading' => 'Tägliche Zusammenfassung',
        'title' => 'Tägliche Zusammenfassung aktivieren',
        'description' => 'Enthält überfällige und heute fällige Aufgaben. Jeder Arbeitsbereich sendet seine eigene E-Mail jeden Morgen, wenn es Aufgaben fällig oder überfällig sind.',
    ],

    'collaboration' => [
        'heading' => 'Kollaborationsbenachrichtigungen',
        'notify_me_about' => 'Benachrichtigen Sie mich über',
    ],

    'channels' => [
        'in_app' => 'App',
        'email' => 'E-Mail',
    ],

    'types' => [
        'task_assigned' => [
            'label' => 'Aufgaben-Zuweisungen',
            'description' => 'Benachrichtigen Sie mich, wenn mir eine Aufgabe zugewiesen wird.',
        ],
        'email_access' => [
            'label' => 'E-Mail-Zugang',
            'description' => 'Benachrichtigen Sie mich, wenn jemand eine E-Mail anfragt, die ich besitze. Benachrichtigen Sie mich, wenn meine eigene Anfrage genehmigt, verweigert oder abläuft.',
        ],
        'email_shared' => [
            'label' => 'Geteilte E-Mails',
            'description' => 'Benachrichtigen Sie mich, wenn jemand eine E-Mail mit mir teilt.',
        ],
        'task_digest' => [
            'label' => 'Tägliche Zusammenfassung',
            'description' => 'Benachrichtigen Sie mich jeden Morgen über überfällige und heute fällige Aufgaben.',
        ],
    ],

    'events' => [
        'task_assigned' => [
            'title' => ':actor hat Ihnen eine Aufgabe zugewiesen',
            'title_without_actor' => 'Sie haben eine neue Aufgabe',
            'action' => 'Aufgabe anzeigen',
        ],
        'table_layout_changed' => [
            'deleted_title' => ':actor hat Ihr Layout ":name" gelöscht',
            'deleted_title_without_actor' => 'Ihr Layout ":name" wurde gelöscht',
            'deleted_body' => 'Es war auf der :table-Tabelle. Mitglieder, die es verwendeten, behalten das, was ihre Tabelle zeigt.',
            'unshared_title' => ':actor hat Ihr Layout ":name" aufgehört zu teilen',
            'unshared_title_without_actor' => 'Ihr Layout ":name" wird nicht mehr geteilt',
            'unshared_body' => 'Es ist für Sie wieder privat auf der :table Tabelle.',
            'renamed_title' => ':actor hat Ihr Layout ":previous" in ":name" umbenannt',
            'renamed_title_without_actor' => 'Ihr Layout ":previous" heißt jetzt ":name"',
            'renamed_body' => 'Es wird weiterhin auf der :table Tabelle geteilt.',
            'updated_title' => ':actor hat Ihr Layout ":name" geändert',
            'updated_title_without_actor' => 'Ihr Layout ":name" wurde geändert',
            'updated_body' => 'Seine Spalten, Sortierung oder Filter wurden auf der :table Tabelle geändert. Öffnen Sie es, um die neue Version zu sehen.',
            'action' => ':table öffnen',
        ],
    ],

    'panel' => [
        'heading' => 'Posteingang',
        'open' => 'Posteingang öffnen',
        'open_with_count' => 'Posteingang öffnen, :count Element benötigt Ihre Aufmerksamkeit|Posteingang öffnen, :count Elemente benötigen Ihre Aufmerksamkeit',
        'close' => 'Posteingang schließen',
        'settings' => 'Benachrichtigungseinstellungen',
        'announce' => ':count neue Benachrichtigung|:count neue Benachrichtigungen',
        'announcements' => [
            'archived' => 'Archiviert: :title',
            'approved' => 'Genehmigt: :title',
            'denied' => 'Verweigert: :title',
            'already_decided' => 'Diese Anfrage wurde bereits entschieden: :title',
        ],
        'footer' => 'Zeigt die neuesten :count Benachrichtigungen.',
        'tabs' => [
            'label' => 'Posteingangsabschnitte',
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
            'deny' => 'Verweigern',
            'approved' => 'Genehmigt',
            'denied' => 'Verweigert',
            'already_decided' => 'Diese Anfrage wurde bereits entschieden.',
            'expires' => 'Läuft am :when ab',
        ],
        'empty' => [
            'notifications' => [
                'heading' => 'Sie sind auf dem Laufenden',
                'description' => 'Aufgaben-Zuweisungen, Importe und Exporte und andere Updates in diesem Arbeitsbereich landen hier.',
            ],
            'requests' => [
                'heading' => 'Keine Anfragen warten',
                'description' => 'Wenn ein Teammitglied etwas anfragt, das Sie besitzen, entscheiden Sie sich hier.',
            ],
            'settings' => 'Einstellungen',
        ],
    ],

    'saved' => 'Benachrichtigungseinstellungen aktualisiert.',
];