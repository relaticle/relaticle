<?php

declare(strict_types=1);

return [
    'connection' => [
        'fallback_name' => 'Ein KI-Assistent',
    ],

    'consent' => [
        'title' => ':client autorisieren',
        'intro' => [
            'one' => ':client möchte sich mit :workspace verbinden.',
            'choose' => ':client möchte sich mit einem Ihrer Arbeitsbereiche verbinden.',
        ],
        'redirect' => 'Nach der Autorisierung gelangen Sie zurück zu :host. Fahren Sie nur fort, wenn Sie dieser Website vertrauen.',
        'signed_in_as' => 'Angemeldet als :email.',
        'invalid_resource' => 'Diese Verbindung hat Zugriff auf einen anderen Server angefordert. Relaticle autorisiert nur Verbindungen zum eigenen MCP-Server.',

        'workspace' => [
            'heading' => 'Welcher Arbeitsbereich?',
            'description' => 'Um später einen anderen zu verwenden, widerrufen Sie diesen Konnektor und fügen ihn erneut hinzu.',
            'aria_label' => 'Auswahl des Arbeitsbereichs',
            'personal' => 'Persönlich',
            'paused' => 'Pausiert. Abonnieren, um zu verbinden',
            'all_paused' => 'Jeder Arbeitsbereich dieses Kontos ist pausiert. Schließen Sie vor dem Verbinden einen Cloud-Tarif ab. Ein Konnektor, der für einen pausierten Arbeitsbereich autorisiert wurde, kann keine Daten lesen oder schreiben.',
            'none' => [
                'heading' => 'Sie gehören keinem Arbeitsbereich an.',
                'description' => 'Erstellen Sie einen Arbeitsbereich in Relaticle oder treten Sie einem bei, bevor Sie diesen Konnektor autorisieren.',
            ],
        ],

        'permissions' => [
            'heading' => ':client kann',
            'read' => 'Ihre Einträge lesen und durchsuchen',
            'write' => 'Einträge erstellen und aktualisieren',
            'delete' => 'Einträge löschen. Gelöschte Einträge lassen sich wiederherstellen.',
            'email_read' => 'E-Mails lesen, die Sie in diesem Arbeitsbereich sehen können',
            'email_draft' => 'E-Mail-Entwürfe speichern, die Sie prüfen können',
            'email_send' => 'E-Mails in Ihrem Namen senden, nach einer Wartezeit, die Sie abbrechen können',
            'none' => 'Ihre Rolle in diesem Arbeitsbereich erlaubt nichts von dem, was :client angefordert hat.',
            'excluded' => 'Auf Ihre anderen Arbeitsbereiche, Mitglieder, die Abrechnung und die Kontoeinstellungen hat er keinen Zugriff.',
        ],

        'actions' => [
            'cancel' => 'Abbrechen',
            'authorize' => 'Autorisieren',
            'authorizing' => 'Autorisierung läuft…',
        ],

        'revoke_hint' => 'Sie können diesen Konnektor jederzeit auf der Seite „Zugriffstoken“ widerrufen.',
    ],
];
