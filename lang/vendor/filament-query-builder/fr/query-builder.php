<?php

declare(strict_types=1);

return [
    'form' => [
        'or_groups' => [
            'group' => [
                'label' => 'Groupe',
            ],
            'block' => [
                'picker_group' => 'Logique',
            ],
        ],
    ],
    'max_rules_reached_tooltip' => 'Vous avez atteint le maximum de :count règles.',
    'operators' => [
        'date' => [
            'unit_labels' => [
                'second' => 'Secondes',
                'minute' => 'Minutes',
                'hour' => 'Heures',
                'day' => 'Jours',
                'week' => 'Semaines',
                'month' => 'Mois',
                'quarter' => 'Trimestres',
                'year' => 'Années',
            ],
            'presets' => [
                'past_decade' => 'Dernière décennie',
                'past_5_years' => '5 dernières années',
                'past_2_years' => '2 dernières années',
                'past_year' => 'Dernière année',
                'past_6_months' => '6 derniers mois',
                'past_quarter' => 'Dernier trimestre',
                'past_month' => 'Dernier mois',
                'past_2_weeks' => '2 dernières semaines',
                'past_week' => 'Dernière semaine',
                'past_hour' => 'Dernière heure',
                'past_minute' => 'Dernière minute',
                'this_decade' => 'Cette décennie',
                'this_year' => 'Cette année',
                'this_quarter' => 'Ce trimestre',
                'this_month' => 'Ce mois-ci',
                'today' => 'Aujourd\'hui',
                'this_hour' => 'Cette heure',
                'this_minute' => 'Cette minute',
                'next_minute' => 'Minute suivante',
                'next_hour' => 'Heure suivante',
                'next_week' => 'Semaine suivante',
                'next_2_weeks' => '2 semaines suivantes',
                'next_month' => 'Mois suivant',
                'next_quarter' => 'Trimestre suivant',
                'next_6_months' => '6 mois suivants',
                'next_year' => 'Année suivante',
                'next_2_years' => '2 années suivantes',
                'next_5_years' => '5 années suivantes',
                'next_decade' => 'Décennie suivante',
                'custom' => 'Personnalisé',
            ],
            'form' => [
                'mode' => [
                    'label' => 'Type de date',
                    'options' => [
                        'absolute' => 'Date précise',
                        'relative' => 'Période glissante',
                    ],
                ],
                'preset' => [
                    'label' => 'Période',
                ],
                'relative_value' => [
                    'label' => 'Combien',
                ],
                'relative_unit' => [
                    'label' => 'Unité de temps',
                ],
                'tense' => [
                    'label' => 'Temps',
                    'options' => [
                        'past' => 'Passé',
                        'future' => 'Futur',
                    ],
                ],
            ],
        ],
    ],
];
