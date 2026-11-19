<?php

declare(strict_types=1);

return [
    'label' => 'tarea',
    'plural_label' => 'tareas',
    'navigation_label' => 'Tareas',

    'fields' => [
        'title' => [
            'label' => 'Título',
        ],
        'assignees' => [
            'label' => 'Asignados',
            'placeholder' => 'Asignar a un miembro',
        ],
        'companies' => [
            'label' => 'Empresas',
        ],
        'people' => [
            'label' => 'Personas',
        ],
        'opportunities' => [
            'label' => 'Oportunidades',
        ],
        'creator' => [
            'label' => 'Creada por',
        ],
        'created_at' => [
            'label' => 'Creada el',
        ],
        'updated_at' => [
            'label' => 'Actualizada el',
        ],
        'deleted_at' => [
            'label' => 'Eliminada el',
        ],
    ],

    'actions' => [
        'copy_url' => 'Copiar URL de la tarea',
        'copy_id' => 'Copiar ID de la tarea',
    ],

    'empty_state' => [
        'description' => 'Las tareas que agregue aparecen aquí.',
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
                'export' => [
                    'label' => 'Exportar tareas',
                    'heading' => 'Exportar tareas',
                ],
            ],
        ],
    ],
];
