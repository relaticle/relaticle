<?php

declare(strict_types=1);

return [
    'notice' => [
        'subject' => 'Pausierte Arbeitsbereiche aus der Testphase löschen wir künftig nach 60 Tagen',
        'preheader' => ':workspace ist bis zum :date sicher. Wählen Sie einen Tarif oder melden Sie sich an, um ihn länger zu behalten.',
        'heading' => 'Pausierte Arbeitsbereiche aus der Testphase löschen wir künftig nach 60 Tagen',
        'body' => ':workspace ist seit dem Ende der Testphase pausiert. Ab heute löschen wir einen pausierten Arbeitsbereich aus der Testphase nach 60 Tagen ohne Anmeldung.',
        'date' => 'Ihre 60 Tage beginnen heute. Wenn sich niemand anmeldet und niemand einen Tarif wählt, löschen wir :workspace am :date.',
        'warn' => 'Wir schreiben Ihnen :warning Tage und :reminder Tage vorher per E-Mail.',
        'cta' => 'Tarif wählen',
    ],
    'warning' => [
        'subject' => ':workspace wird am :date gelöscht',
        'preheader' => 'Seit dem :since hat sich niemand angemeldet. Wählen Sie einen Tarif oder melden Sie sich an, um ihn zu behalten.',
        'heading' => ':workspace wird am :date gelöscht',
        'paused' => ':workspace ist pausiert, und seit dem :since hat sich niemand angemeldet.',
        'rule' => 'Wir löschen einen pausierten Arbeitsbereich aus der Testphase nach 60 Tagen ohne Anmeldung. Am :date löschen wir :workspace und jeden Eintrag darin. Das lässt sich nicht rückgängig machen.',
        'keep' => 'Um den Arbeitsbereich zu behalten, wählen Sie vorher einen Tarif.',
        'cta' => 'Tarif wählen',
        'sign_in' => 'Auch eine Anmeldung sichert ihn für weitere 60 Tage.',
    ],
    'reminder' => [
        'subject' => ':workspace wird am :date gelöscht',
        'preheader' => 'Das ist die letzte Erinnerung. Wählen Sie einen Tarif oder melden Sie sich an, um ihn zu behalten.',
        'heading' => ':workspace wird am :date gelöscht',
        'body' => 'Wir löschen :workspace und jeden Eintrag darin am :date. Das lässt sich nicht rückgängig machen.',
        'keep' => 'Wählen Sie vorher einen Tarif oder melden Sie sich an, um ihn zu behalten.',
    ],
    'deleted' => [
        'subject' => ':workspace wurde gelöscht',
        'preheader' => 'Er war 60 Tage ohne Anmeldung pausiert.',
        'heading' => ':workspace wurde gelöscht',
        'body' => ':workspace war 60 Tage ohne Anmeldung pausiert, daher haben wir ihn samt seinen Einträgen gelöscht.',
        'restart' => 'Sie können sich jederzeit anmelden und einen neuen Arbeitsbereich starten.',
    ],
];
