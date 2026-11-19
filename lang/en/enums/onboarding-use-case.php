<?php

declare(strict_types=1);

return [
    'sales' => 'Sales',
    'customer_success' => 'Customer Success',
    'recruiting' => 'Recruiting',
    'marketing' => 'Marketing',
    'fundraising' => 'Fundraising',
    'investing' => 'Investing',
    'other' => 'Other',
    'descriptions' => [
        'sales' => 'Track deals, manage pipeline stages, and close revenue',
        'customer_success' => 'Manage renewals, health scores, and customer relationships',
        'recruiting' => 'Manage candidates, interviews, and hiring workflows',
        'marketing' => 'Track campaigns, leads, and marketing performance',
        'fundraising' => 'Manage investor outreach, rounds, and due diligence',
        'investing' => 'Track deal flow, portfolio companies, and investment pipeline',
        'other' => 'Organize people, companies, and tasks your way',
    ],
    'sub_options' => [
        'sales' => [
            'outbound' => 'Outbound',
            'inbound' => 'Inbound',
            'product_led' => 'Product-led',
            'partner_led' => 'Partner-led',
        ],
        'customer_success' => [
            'high_touch' => 'High-touch',
            'low_touch' => 'Low-touch',
        ],
        'recruiting' => [
            'applications' => 'Applications',
            'sourcing' => 'Sourcing',
        ],
        'marketing' => [
            'content' => 'Content',
            'demand_gen' => 'Demand gen',
            'events' => 'Events',
            'partnerships' => 'Partnerships',
        ],
        'fundraising' => [
            'early_stage' => 'Early-stage',
            'growth_stage' => 'Growth-stage',
            'late_stage' => 'Late-stage',
        ],
        'investing' => [
            'early_stage' => 'Early-stage',
            'growth_stage' => 'Growth-stage',
            'late_stage' => 'Late-stage',
        ],
    ],
    'stages' => [
        'customer_success' => [
            'onboarding' => 'Onboarding',
            'active' => 'Active',
            'renewal_due' => 'Renewal due',
            'at_risk' => 'At risk',
            'renewed' => 'Renewed',
            'churned' => 'Churned',
        ],
        'recruiting' => [
            'sourced' => 'Sourced',
            'applied' => 'Applied',
            'screen' => 'Screen',
            'interview' => 'Interview',
            'offer' => 'Offer',
            'hired' => 'Hired',
            'declined' => 'Declined',
        ],
        'fundraising' => [
            'target' => 'Target',
            'intro' => 'Intro',
            'first_meeting' => 'First meeting',
            'partner_meeting' => 'Partner meeting',
            'term_sheet' => 'Term sheet',
            'closed' => 'Closed',
            'passed' => 'Passed',
        ],
    ],
];
