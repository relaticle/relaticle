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
        'redirect' => 'Nachdem Sie autorisiert haben, kehren Sie zu :host zurück. Fortfahren nur, wenn Sie diesem Site vertrauen.',
        'signed_in_as' => 'Angemeldet als :email.',
        'invalid_resource' => 'Diese Verbindung hat um Zugang zu einem anderen Server angefragt. Relaticle autorisiert Verbindungen nur zu seinem eigenen MCP Server.',

        'workspace' => [
            'heading' => 'Welcher Arbeitsbereich?',
            'description' => 'Um einen anderen später zu verwenden, revokieren Sie diese Verbindung und fügen Sie sie erneut hinzu.',
            'aria_label' => 'Arbeitsbereich-Auswahl',
            'personal' => 'Persönlich',
            'paused' => 'Pausiert. Abonnieren Sie zum Verbinden',
            'all_paused' => 'Jeder Arbeitsbereich auf diesem Konto ist pausiert. Abonnieren Sie einen Cloud-Plan, bevor Sie verbinden. Eine Verbindung, die gegen einen pausierten Arbeitsbereich autorisiert wurde, kann keine Daten lesen oder schreiben.',
            'none' => [
                'heading' => 'Sie gehören keinem Arbeitsbereich an.',
                'description' => 'Erstellen oder treten Sie einem Arbeitsbereich in Relaticle bei, bevor Sie diese Verbindung autorisieren.',
            ],
        ],

        'permissions' => [
            'heading' => ':client wird in der Lage sein',
            'read' => 'Ihre Aufzeichnungen lesen und suchen',
            'write' => 'Sie erstellen und aktualisieren',
            'delete' => 'Löschen. Sie können gelöschte Aufzeichnungen wiederherstellen.',
            'email_read' => 'Die E-Mail lesen, die Sie in diesem Arbeitsbereich sehen können',
            'email_draft' => 'E-Mail-Entwürfe für Sie speichern zum Überprüfen',
            'email_send' => 'E-Mails als Sie senden, mit einer Verzögerung zum Abbrechen.',
            'none' => 'Ihre Rolle in diesem Arbeitsbereich erlaubt keine der von :client angefragten Berechtigungen.',
            'excluded' => 'Es kann nicht auf Ihre anderen Arbeitsbereiche, Arbeitsbereichsmitglieder, Abrechnung oder Kontoeinstellungen zugreifen.',
        ],

        'actions' => [
            'cancel' => 'Abbrechen',
            'authorize' => 'Autorisieren',
            'authorizing' => 'Autorisieren...',
        ],

        'revoke_hint' => 'Revokieren Sie diese Verbindung jederzeit von der Zugangstoken-Seite aus.',
    ],
];
