<?php

declare(strict_types=1);

return [
    'sales' => 'Ventas',
    'customer_success' => 'Éxito del cliente',
    'recruiting' => 'Reclutamiento',
    'marketing' => 'Marketing',
    'fundraising' => 'Recaudación de fondos',
    'investing' => 'Inversión',
    'other' => 'Otro',
    'descriptions' => [
        'sales' => 'Dé seguimiento a sus oportunidades, administre las etapas del pipeline y cierre ventas',
        'customer_success' => 'Administre renovaciones, indicadores de salud y relaciones con los clientes',
        'recruiting' => 'Administre candidatos, entrevistas y procesos de contratación',
        'marketing' => 'Dé seguimiento a campañas, leads y al rendimiento de marketing',
        'fundraising' => 'Administre el contacto con inversionistas, rondas y la debida diligencia',
        'investing' => 'Dé seguimiento al flujo de operaciones, a las empresas del portafolio y al pipeline de inversión',
        'other' => 'Organice personas, empresas y tareas a su manera',
    ],
    'sub_options' => [
        'sales' => [
            'outbound' => 'Saliente',
            'inbound' => 'Entrante',
            'product_led' => 'Impulsado por el producto',
            'partner_led' => 'Impulsado por socios',
        ],
        'customer_success' => [
            'high_touch' => 'Atención personalizada',
            'low_touch' => 'Atención mínima',
        ],
        'recruiting' => [
            'applications' => 'Postulaciones',
            'sourcing' => 'Búsqueda activa',
        ],
        'marketing' => [
            'content' => 'Contenido',
            'demand_gen' => 'Generación de demanda',
            'events' => 'Eventos',
            'partnerships' => 'Alianzas',
        ],
        'fundraising' => [
            'early_stage' => 'Etapa temprana',
            'growth_stage' => 'Etapa de crecimiento',
            'late_stage' => 'Etapa avanzada',
        ],
        'investing' => [
            'early_stage' => 'Etapa temprana',
            'growth_stage' => 'Etapa de crecimiento',
            'late_stage' => 'Etapa avanzada',
        ],
    ],
    'stages' => [
        'customer_success' => [
            'onboarding' => 'Incorporación',
            'active' => 'Activo',
            'renewal_due' => 'Renovación pendiente',
            'at_risk' => 'En riesgo',
            'renewed' => 'Renovado',
            'churned' => 'Perdido',
        ],
        'recruiting' => [
            'sourced' => 'Identificado',
            'applied' => 'Postulado',
            'screen' => 'Preselección',
            'interview' => 'Entrevista',
            'offer' => 'Oferta',
            'hired' => 'Contratado',
            'declined' => 'Rechazado',
        ],
        'fundraising' => [
            'target' => 'Objetivo',
            'intro' => 'Presentación',
            'first_meeting' => 'Primera reunión',
            'partner_meeting' => 'Reunión con socios',
            'term_sheet' => 'Hoja de términos',
            'closed' => 'Cerrado',
            'passed' => 'Descartado',
        ],
    ],
];
