<?php

declare(strict_types=1);

return [
    'active' => 'Aktiv',
    'trialing' => 'Testphase',
    'past_due' => 'Überfällig',
    'unpaid' => 'Unbezahlt',
    'canceled' => 'Gekündigt',
    'incomplete' => 'Unvollständig',
    'incomplete_expired' => 'Unvollständig abgelaufen',
    'paused' => 'Pausiert',
    'descriptions' => [
        'active' => 'Bezahlt und aktuell. Die letzte Rechnung wurde erfolgreich abgebucht.',
        'trialing' => 'In einer Stripe-Testphase. Bisher wurde nichts abgebucht.',
        'past_due' => 'Die letzte Rechnung ist fehlgeschlagen. Stripe versucht es erneut, und der Zugriff bleibt offen, bis Stripe aufgibt.',
        'unpaid' => 'Stripe hat alle Wiederholungsversuche ausgeschöpft und aufgehört. Der Zugriff ist weg.',
        'canceled' => 'Beendet. Es werden keine weiteren Rechnungen erstellt.',
        'incomplete' => 'Die erste Zahlung wurde nie abgeschlossen, daher hat das Abonnement nie begonnen.',
        'incomplete_expired' => 'Die erste Zahlung wurde abgebrochen, und Stripe hat den Versuch verfallen lassen.',
        'paused' => 'Am Ende einer Testphase pausiert, in der keine Zahlungsmethode hinterlegt wurde.',
    ],
];
