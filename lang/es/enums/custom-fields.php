<?php

declare(strict_types=1);

return [
    'company' => [
        'icp' => ['name' => 'ICP'],
        'domains' => ['name' => 'Dominios'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'people' => [
        'emails' => ['name' => 'Correos electrónicos'],
        'phone_number' => ['name' => 'Número de teléfono'],
        'job_title' => ['name' => 'Cargo'],
        'linkedin' => ['name' => 'LinkedIn'],
    ],
    'opportunity' => [
        'amount' => ['name' => 'Monto'],
        'close_date' => ['name' => 'Fecha de cierre'],
        'stage' => [
            'name' => 'Etapa',
            'options' => [
                'prospecting' => 'Prospección',
                'qualification' => 'Calificación',
                'needs_analysis' => 'Análisis de necesidades',
                'value_proposition' => 'Propuesta de valor',
                'id_decision_makers' => 'Id. de decisores',
                'perception_analysis' => 'Análisis de percepción',
                'proposal_price_quote' => 'Propuesta/Cotización',
                'negotiation_review' => 'Negociación/Revisión',
                'closed_won' => 'Cerrada ganada',
                'closed_lost' => 'Cerrada perdida',
            ],
        ],
    ],
    'task' => [
        'status' => [
            'name' => 'Estado',
            'options' => [
                'to_do' => 'Por hacer',
                'in_progress' => 'En curso',
                'done' => 'Hecha',
            ],
        ],
        'priority' => [
            'name' => 'Prioridad',
            'options' => [
                'low' => 'Baja',
                'medium' => 'Media',
                'high' => 'Alta',
            ],
        ],
        'description' => ['name' => 'Descripción'],
        'due_date' => ['name' => 'Fecha de vencimiento'],
    ],
    'note' => [
        'body' => ['name' => 'Contenido'],
    ],
];
