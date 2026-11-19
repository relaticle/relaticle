<?php

declare(strict_types=1);

return [
    'sales' => 'Ventes',
    'customer_success' => 'Succès client',
    'recruiting' => 'Recrutement',
    'marketing' => 'Marketing',
    'fundraising' => 'Levée de fonds',
    'investing' => 'Investissement',
    'other' => 'Autre',
    'descriptions' => [
        'sales' => 'Suivez vos opportunités, gérez les étapes du pipeline et concluez vos ventes',
        'customer_success' => 'Gérez les renouvellements, les scores de santé et les relations clients',
        'recruiting' => 'Gérez les candidats, les entretiens et les processus d\'embauche',
        'marketing' => 'Suivez les campagnes, les leads et les performances marketing',
        'fundraising' => 'Gérez les relances d\'investisseurs, les tours de table et les due diligences',
        'investing' => 'Suivez le flux d\'opportunités, les entreprises du portefeuille et le pipeline d\'investissement',
        'other' => 'Organisez personnes, entreprises et tâches à votre façon',
    ],
    'sub_options' => [
        'sales' => [
            'outbound' => 'Sortant',
            'inbound' => 'Entrant',
            'product_led' => 'Orienté produit',
            'partner_led' => 'Orienté partenaires',
        ],
        'customer_success' => [
            'high_touch' => 'Accompagnement renforcé',
            'low_touch' => 'Accompagnement léger',
        ],
        'recruiting' => [
            'applications' => 'Candidatures',
            'sourcing' => 'Sourcing',
        ],
        'marketing' => [
            'content' => 'Contenu',
            'demand_gen' => 'Génération de demande',
            'events' => 'Événements',
            'partnerships' => 'Partenariats',
        ],
        'fundraising' => [
            'early_stage' => 'Phase d\'amorçage',
            'growth_stage' => 'Phase de croissance',
            'late_stage' => 'Phase avancée',
        ],
        'investing' => [
            'early_stage' => 'Phase d\'amorçage',
            'growth_stage' => 'Phase de croissance',
            'late_stage' => 'Phase avancée',
        ],
    ],
    'stages' => [
        'customer_success' => [
            'onboarding' => 'Intégration',
            'active' => 'Actif',
            'renewal_due' => 'Renouvellement à venir',
            'at_risk' => 'À risque',
            'renewed' => 'Renouvelé',
            'churned' => 'Perdu',
        ],
        'recruiting' => [
            'sourced' => 'Identifié',
            'applied' => 'Candidature reçue',
            'screen' => 'Présélection',
            'interview' => 'Entretien',
            'offer' => 'Offre',
            'hired' => 'Embauché',
            'declined' => 'Refusé',
        ],
        'fundraising' => [
            'target' => 'Cible',
            'intro' => 'Introduction',
            'first_meeting' => 'Premier rendez-vous',
            'partner_meeting' => 'Rendez-vous avec les associés',
            'term_sheet' => 'Term sheet',
            'closed' => 'Conclu',
            'passed' => 'Décliné',
        ],
    ],
];
