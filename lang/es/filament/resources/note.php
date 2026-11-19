<?php

declare(strict_types=1);

return [
    'label' => 'nota',
    'plural_label' => 'notas',
    'navigation_label' => 'Notas',

    'fields' => [
        'title' => [
            'label' => 'Título',
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

    'filters' => [
        'creation_source' => [
            'label' => 'Origen de creación',
        ],
    ],

    'created_periods' => [
        'today' => 'Creadas hoy',
        'this_week' => 'Creadas esta semana',
        'this_month' => 'Creadas este mes',
        'this_year' => 'Creadas este año',
        'earlier' => 'Creadas antes',
    ],

    'cards' => [
        'untitled' => 'Nota sin título',
        'no_content' => 'Esta nota no tiene contenido.',
        'today' => 'Hoy',
        'yesterday' => 'Ayer',
        'deleted' => 'Eliminada',
    ],

    'empty_state' => [
        'description' => 'Las notas que agregue aparecen aquí.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importar notas',
                ],
                'import_export' => [
                    'label' => 'Importar / Exportar',
                ],
                'export' => [
                    'label' => 'Exportar notas',
                    'heading' => 'Exportar notas',
                ],
            ],
        ],
    ],
];
