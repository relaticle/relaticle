<?php

declare(strict_types=1);

return [
    // Singular/plural en minúscula para que el botón "Crear :label" de Filament se lea bien.
    'label' => 'tarea',
    'plural_label' => 'tareas',
    'navigation_label' => 'Tareas',

    'fields' => [
        'assignees' => [
            'label' => 'Asignada a',
        ],
        'companies' => [
            'label' => 'Empresas',
        ],
        'people' => [
            'label' => 'Contactos',
        ],
        'creator' => [
            'label' => 'Creado por',
        ],
        'created_at' => [
            'label' => 'Fecha de creación',
        ],
        'updated_at' => [
            'label' => 'Última actualización',
        ],
        'deleted_at' => [
            'label' => 'Fecha de eliminación',
        ],
    ],

    'filters' => [
        'assigned_to_me' => [
            'label' => 'Asignadas a mí',
        ],
        'creation_source' => [
            'label' => 'Origen de creación',
        ],
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importar tareas',
                ],
                'import_export' => [
                    'label' => 'Importar / Exportar',
                ],
            ],
        ],
    ],
];
