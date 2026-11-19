<?php

declare(strict_types=1);

return [
    'subheading' => 'Aktualisieren Sie die Berechtigungen und Einstellungen Ihres Postfachs.',
    'tabs' => [
        'general' => 'Allgemein',
        'blocklist' => 'Sperrliste nur für dieses Postfach',
        'signatures' => 'Signaturen',
    ],
    'general' => [
        'sync_heading' => 'Synchronisierung und Versand',
    ],
    'sharing' => [
        'label' => 'E-Mail-Freigabe',
        'hint' => 'Was Ihr Arbeitsbereich aus diesem Postfach sieht. Andere Postfächer behalten ihre eigene Stufe.',
    ],
    'blocklist' => [
        'label' => 'Gesperrte Adressen und Domains',
        'emails_label' => 'Gesperrte Adressen',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Drücken Sie die Eingabetaste, um jede Adresse hinzuzufügen.',
        'domains_label' => 'Gesperrte Domains',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Drücken Sie die Eingabetaste, um jede Domain hinzuzufügen.',
        'include_subdomains_label' => 'Subdomains einschließen',
        'include_subdomains_hint' => 'Blendet beim Sperren von example.com auch E-Mails von Adressen wie user@mail.example.com aus.',
        'include_subdomains_column' => 'Subdomains',
        'include_subdomains_short' => 'Subdomains einschließen',
        'hint' => 'Nur in diesem Postfach ausgeblendet. Andere verbundene Postfächer sind nicht betroffen.',
        'add' => 'Zur Sperrliste hinzufügen',
        'empty_heading' => 'Noch keine Adressen oder Domains',
        'empty_description' => 'E-Mails von gesperrten Domains und Adressen erscheinen nicht in diesem Postfach.',
        'notifications' => [
            'added' => 'Sperrliste aktualisiert.',
            'deleted' => 'Eintrag der Sperrliste entfernt.',
        ],
    ],
    'signatures' => [
        'label' => 'Signaturen',
        'hint' => 'Wird beim Verfassen aus diesem Postfach verwendet.',
        'add' => 'Signatur hinzufügen',
        'empty_heading' => 'Noch keine Signaturen',
        'empty_description' => 'Erstellen Sie eine Signatur, die an E-Mails aus diesem Postfach angehängt wird.',
    ],
    'actions' => [
        'save' => 'Änderungen speichern',
    ],
    'notifications' => [
        'saved' => 'Postfacheinstellungen gespeichert.',
    ],
];
