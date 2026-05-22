<?php

declare(strict_types=1);

return [
    // Singular/plural en minúsculas para que el botón "Nueva :label" se lea con naturalidad.
    'label' => 'tarea',
    'plural_label' => 'tareas',
    'navigation_label' => 'Tareas',

    'fields' => [
        'assignees' => [
            'label' => 'Asignados',
        ],
        'companies' => [
            'label' => 'Empresas',
        ],
        'people' => [
            'label' => 'Personas',
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
            'label' => 'Eliminado el',
        ],
    ],

    'filters' => [
        'assigned_to_me' => [
            'label' => 'Asignadas a mí',
        ],
        'creation_source' => [
            'label' => 'Fuente de creación',
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
