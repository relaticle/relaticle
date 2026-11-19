<?php

declare(strict_types=1);

return [
    'active' => 'Actif',
    'trialing' => 'En essai',
    'past_due' => 'Paiement en retard',
    'unpaid' => 'Impayé',
    'canceled' => 'Annulé',
    'incomplete' => 'Incomplet',
    'incomplete_expired' => 'Incomplet expiré',
    'paused' => 'En pause',
    'descriptions' => [
        'active' => 'Payé et à jour. La dernière facture a été prélevée avec succès.',
        'trialing' => 'Dans un essai Stripe. Rien n\'a encore été facturé.',
        'past_due' => 'La dernière facture a échoué. Stripe réessaie, et l\'accès reste ouvert jusqu\'à son abandon.',
        'unpaid' => 'Stripe a épuisé ses tentatives et a cessé d\'essayer. L\'accès est coupé.',
        'canceled' => 'Terminé. Aucune autre facture ne sera émise.',
        'incomplete' => 'Le premier paiement n\'a jamais abouti, donc l\'abonnement n\'a jamais démarré.',
        'incomplete_expired' => 'Le premier paiement a été abandonné et Stripe a fait expirer la tentative.',
        'paused' => 'Mis en pause à la fin d\'un essai qui n\'a recueilli aucun moyen de paiement.',
    ],
];
