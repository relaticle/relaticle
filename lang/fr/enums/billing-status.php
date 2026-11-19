<?php

declare(strict_types=1);

return [
    'past_due' => 'Paiement en retard',
    'subscribed' => 'Abonné',
    'enterprise' => 'Enterprise',
    'trialing' => 'Essai',
    'grandfathered' => 'Gratuit (ancien)',
    'subscription_ended' => 'Abonnement terminé',
    'trial_ended' => 'Essai terminé',
    'granted' => 'Accordé',
    'free' => 'Gratuit',
    'descriptions' => [
        'past_due' => 'Abonnement payant dont le dernier paiement a échoué. L\'accès reste ouvert jusqu\'à l\'annulation par Stripe.',
        'subscribed' => 'Paie Cloud Pro ou Cloud Scale par un abonnement Stripe actif.',
        'enterprise' => 'Placé manuellement sur l\'offre Enterprise. Aucun tarif en libre-service n\'existe pour elle.',
        'trialing' => 'Période d\'essai Pro en cours. Rien n\'a encore été facturé.',
        'grandfathered' => 'Existait avant le lancement de la facturation, donc l\'accès hébergé reste gratuit pour toujours.',
        'subscription_ended' => 'Abonnement payant terminé, sans rien derrière. L\'accès hébergé est suspendu jusqu\'à ce que le propriétaire se réabonne.',
        'trial_ended' => 'Essai Pro expiré sans abonnement. L\'accès hébergé est suspendu jusqu\'à ce que le propriétaire s\'abonne.',
        'granted' => 'Offre payante accordée manuellement, sans abonnement ni essai derrière.',
        'free' => 'Aucun abonnement, essai ou statut d\'ancien client. L\'accès hébergé est suspendu tant que la facturation est active.',
    ],
];
