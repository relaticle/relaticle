<?php

declare(strict_types=1);

return [
    'columns' => [
        'id' => 'ID',
        'workspace' => 'Espacio de trabajo',
        'account_owner' => 'Responsable',
        'creator' => 'Creado por',
        'creation_source' => 'Origen de creación',
        'created_at' => 'Creado el',
        'updated_at' => 'Actualizado el',
        'deleted_at' => 'Eliminado el',
        'company_name' => 'Nombre de la empresa',
        'people_count' => 'Número de personas',
        'opportunities_count' => 'Número de oportunidades',
        'opportunity_name' => 'Nombre de la oportunidad',
        'company' => 'Empresa',
        'contact_person' => 'Persona de contacto',
        'notes_count' => 'Número de notas',
        'tasks_count' => 'Número de tareas',
    ],

    'notifications' => [
        'completed' => [
            'company' => [
                'body' => 'La exportación de sus empresas se completó (:rows).',
                'failed' => 'Sin exportar: :rows.',
            ],
            'note' => [
                'body' => 'La exportación de sus notas se completó (:rows).',
                'failed' => 'Sin exportar: :rows.',
            ],
            'opportunity' => [
                'body' => 'La exportación de sus oportunidades se completó (:rows).',
                'failed' => 'Sin exportar: :rows.',
            ],
            'people' => [
                'body' => 'La exportación de sus personas se completó (:rows).',
                'failed' => 'Sin exportar: :rows.',
            ],
            'task' => [
                'body' => 'La exportación de sus tareas se completó (:rows).',
                'failed' => 'Sin exportar: :rows.',
            ],
        ],
    ],
];
