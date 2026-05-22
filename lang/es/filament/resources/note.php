<?php

declare(strict_types=1);

return [
    // Singular/plural en minúsculas para que el botón "Nueva :label" se lea con naturalidad.
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
        'creator' => [
            'label' => 'Creado por',
        ],
        'created_at' => [
            'label' => 'Fecha de creación',
        ],
        'updated_at' => [
            'label' => 'Última actualización',
        ],
    ],

    'filters' => [
        'creation_source' => [
            'label' => 'Fuente de creación',
        ],
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
            ],
        ],
    ],
];
