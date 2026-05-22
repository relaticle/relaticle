<?php

declare(strict_types=1);

return [
    'columns' => [
        'id' => 'ID',
        'team' => 'Equipo',
        'account_owner' => 'Responsable de cuenta',
        'creator' => 'Creado por',
        'creation_source' => 'Fuente de creación',
        'created_at' => 'Fecha de creación',
        'updated_at' => 'Última actualización',
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
                'body' => 'La exportación de empresas ha finalizado y se han exportado :rows.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'note' => [
                'body' => 'La exportación de notas ha finalizado y se han exportado :rows.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'opportunity' => [
                'body' => 'La exportación de oportunidades ha finalizado y se han exportado :rows.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'people' => [
                'body' => 'La exportación de personas ha finalizado y se han exportado :rows.',
                'failed' => ':rows no se han podido exportar.',
            ],
            'task' => [
                'body' => 'La exportación de tareas ha finalizado y se han exportado :rows.',
                'failed' => ':rows no se han podido exportar.',
            ],
        ],
    ],
];
