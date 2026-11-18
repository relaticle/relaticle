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
                'body' => 'Su exportación de empresas se completó y se exportaron :rows.',
                'failed' => 'No se pudieron exportar :rows.',
            ],
            'note' => [
                'body' => 'Su exportación de notas se completó y se exportaron :rows.',
                'failed' => 'No se pudieron exportar :rows.',
            ],
            'opportunity' => [
                'body' => 'Su exportación de oportunidades se completó y se exportaron :rows.',
                'failed' => 'No se pudieron exportar :rows.',
            ],
            'people' => [
                'body' => 'Su exportación de personas se completó y se exportaron :rows.',
                'failed' => 'No se pudieron exportar :rows.',
            ],
            'task' => [
                'body' => 'Su exportación de tareas se completó y se exportaron :rows.',
                'failed' => 'No se pudieron exportar :rows.',
            ],
        ],
    ],
];
