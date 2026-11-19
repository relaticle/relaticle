<?php

declare(strict_types=1);

return [
    'past_due' => 'Überfällig',
    'subscribed' => 'Abonniert',
    'enterprise' => 'Enterprise',
    'trialing' => 'Testphase',
    'grandfathered' => 'Kostenlos (Legacy)',
    'subscription_ended' => 'Abonnement beendet',
    'trial_ended' => 'Testphase beendet',
    'granted' => 'Gewährt',
    'free' => 'Kostenlos',
    'descriptions' => [
        'past_due' => 'Bezahltes Abonnement, dessen letzte Abbuchung fehlgeschlagen ist. Der Zugriff bleibt offen, bis Stripe es kündigt.',
        'subscribed' => 'Zahlt für Cloud Pro oder Cloud Scale über ein aktives Stripe-Abonnement.',
        'enterprise' => 'Von Hand auf den Enterprise-Tarif gesetzt. Dafür gibt es keinen Preis zur Selbstbuchung.',
        'trialing' => 'Läuft in einer nicht abgelaufenen Pro-Testphase. Bisher wurde nichts abgebucht.',
        'grandfathered' => 'Bestand schon vor Einführung der Abrechnung, daher bleibt der gehostete Zugriff dauerhaft kostenlos.',
        'subscription_ended' => 'Bezahltes Abonnement endete ohne Nachfolger. Der gehostete Zugriff ist pausiert, bis der Inhaber erneut abonniert.',
        'trial_ended' => 'Pro-Testphase ohne Abonnement abgelaufen. Der gehostete Zugriff ist pausiert, bis der Inhaber abonniert.',
        'granted' => 'Von Hand einen bezahlten Tarif erhalten, ohne dahinterliegendes Abonnement oder Testphase.',
        'free' => 'Kein Abonnement, keine Testphase und keine Bestandsregelung. Der gehostete Zugriff ist pausiert, solange die Abrechnung aktiv ist.',
    ],
];
