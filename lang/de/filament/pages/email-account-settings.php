<?php

declare(strict_types=1);

return [
    'subheading' => 'Aktualisieren Sie Ihre Kontoberechtigungen und -einstellungen.',
    'tabs' => [
        'general' => 'Allgemein',
        'blocklist' => 'Posteingangs-Blockliste',
        'signatures' => 'Signaturen',
    ],
    'general' => [
        'sync_heading' => 'Synchronisierung und Senden',
    ],
    'sharing' => [
        'label' => 'E-Mail-Freigabe',
        'hint' => 'Was Ihr Arbeitsbereich von diesem Posteingang sieht. Andere Posteingänge behalten ihr eigenes Niveau.',
    ],
    'blocklist' => [
        'label' => 'Blockierte Adressen und Domains',
        'emails_label' => 'Blockierte Adressen',
        'emails_placeholder' => 'noisy@example.com',
        'emails_after_label' => 'Drücken Sie die Eingabetaste, um jede Adresse hinzuzufügen.',
        'domains_label' => 'Blockierte Domains',
        'domains_placeholder' => 'example.com',
        'domains_after_label' => 'Drücken Sie die Eingabetaste, um jede Domain hinzuzufügen.',
        'include_subdomains_label' => 'Unterdomänen einschließen',
        'include_subdomains_hint' => 'Verbergen Sie auch E-Mails von Adressen wie user@mail.example.com, wenn Sie example.com blockieren.',
        'include_subdomains_column' => 'Unterdomänen',
        'include_subdomains_short' => 'Unterdomänen einschließen',
        'hint' => 'Nur vor diesem Posteingang verborgen. Andere verbundene Konten sind nicht betroffen.',
        'add' => 'Zur Blockliste hinzufügen',
        'empty_heading' => 'Noch keine Adressen oder Domains',
        'empty_description' => 'E-Mails von blockierten Domains und Adressen werden in diesem Posteingang nicht erscheinen.',
        'notifications' => [
            'added' => 'Blockliste aktualisiert.',
            'deleted' => 'Blocklisteneintrag entfernt.',
        ],
    ],
    'signatures' => [
        'label' => 'Signaturen',
        'hint' => 'Wird verwendet, wenn Sie von diesem Konto aus schreiben.',
        'add' => 'Signatur hinzufügen',
        'empty_heading' => 'Noch keine Signaturen',
        'empty_description' => 'Erstellen Sie eine Signatur, die an E-Mails angehängt wird, die Sie von diesem Konto senden.',
    ],
    'actions' => [
        'save' => 'Änderungen speichern',
    ],
    'notifications' => [
        'saved' => 'Kontoeinstellungen gespeichert.',
    ],
];
