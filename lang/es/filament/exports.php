<?php

declare(strict_types=1);

return [
    'columns' => [
        'id' => 'ID',
        'team' => 'Espacio de trabajo',
        'account_owner' => 'Responsable de la cuenta',
        'creator' => 'Creado por',
        'creation_source' => 'Origen de creación',
        'created_at' => 'Fecha de creación',
        'updated_at' => 'Última actualización',
        'deleted_at' => 'Fecha de eliminación',
        'company_name' => 'Nombre de la empresa',
        'people_count' => 'Número de contactos',
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
                'body' => 'La exportación de empresas ha terminado: :rows exportadas.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'note' => [
                'body' => 'La exportación de notas ha terminado: :rows exportadas.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'opportunity' => [
                'body' => 'La exportación de oportunidades ha terminado: :rows exportadas.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'people' => [
                'body' => 'La exportación de contactos ha terminado: :rows exportados.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'task' => [
                'body' => 'La exportación de tareas ha terminado: :rows exportadas.',
                'failed' => ':rows no se han podido exportar.',
            ],
        ],
    ],
];
