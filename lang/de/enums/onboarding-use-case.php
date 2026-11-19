<?php

declare(strict_types=1);

return [
    'sales' => 'Vertrieb',
    'customer_success' => 'Customer Success',
    'recruiting' => 'Recruiting',
    'marketing' => 'Marketing',
    'fundraising' => 'Fundraising',
    'investing' => 'Investments',
    'other' => 'Sonstiges',
    'descriptions' => [
        'sales' => 'Verkaufschancen verfolgen, Pipeline-Phasen steuern und Abschlüsse erzielen',
        'customer_success' => 'Verlängerungen, Health Scores und Kundenbeziehungen verwalten',
        'recruiting' => 'Kandidaten, Vorstellungsgespräche und Einstellungsprozesse verwalten',
        'marketing' => 'Kampagnen, Leads und Marketingleistung verfolgen',
        'fundraising' => 'Investorenansprache, Finanzierungsrunden und Due Diligence verwalten',
        'investing' => 'Beteiligungsmöglichkeiten, Portfoliounternehmen und Investment-Pipeline verfolgen',
        'other' => 'Personen, Unternehmen und Aufgaben auf Ihre Weise organisieren',
    ],
    'sub_options' => [
        'sales' => [
            'outbound' => 'Outbound',
            'inbound' => 'Inbound',
            'product_led' => 'Produktgetrieben',
            'partner_led' => 'Partnergetrieben',
        ],
        'customer_success' => [
            'high_touch' => 'High-Touch',
            'low_touch' => 'Low-Touch',
        ],
        'recruiting' => [
            'applications' => 'Bewerbungen',
            'sourcing' => 'Sourcing',
        ],
        'marketing' => [
            'content' => 'Content',
            'demand_gen' => 'Demand Generation',
            'events' => 'Events',
            'partnerships' => 'Partnerschaften',
        ],
        'fundraising' => [
            'early_stage' => 'Frühphase',
            'growth_stage' => 'Wachstumsphase',
            'late_stage' => 'Spätphase',
        ],
        'investing' => [
            'early_stage' => 'Frühphase',
            'growth_stage' => 'Wachstumsphase',
            'late_stage' => 'Spätphase',
        ],
    ],
    'stages' => [
        'customer_success' => [
            'onboarding' => 'Onboarding',
            'active' => 'Aktiv',
            'renewal_due' => 'Verlängerung fällig',
            'at_risk' => 'Gefährdet',
            'renewed' => 'Verlängert',
            'churned' => 'Abgewandert',
        ],
        'recruiting' => [
            'sourced' => 'Gesourct',
            'applied' => 'Beworben',
            'screen' => 'Vorauswahl',
            'interview' => 'Vorstellungsgespräch',
            'offer' => 'Angebot',
            'hired' => 'Eingestellt',
            'declined' => 'Abgelehnt',
        ],
        'fundraising' => [
            'target' => 'Zielinvestor',
            'intro' => 'Erstkontakt',
            'first_meeting' => 'Erstes Treffen',
            'partner_meeting' => 'Partnertreffen',
            'term_sheet' => 'Term Sheet',
            'closed' => 'Abgeschlossen',
            'passed' => 'Abgesagt',
        ],
    ],
];
