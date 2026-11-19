<?php

declare(strict_types=1);

return [
    'label' => 'empresa',
    'plural_label' => 'empresas',
    'navigation_label' => 'Empresas',

    'fields' => [
        'name' => [
            'label' => 'Empresa',
        ],
        'account_owner' => [
            'label' => 'Responsable',
        ],
        'account_owner_id' => [
            'label' => 'Responsable',
        ],
        'created_by' => [
            'label' => 'Creado por',
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

    'empty_state' => [
        'description' => 'Las empresas que agregue o importe aparecen aquí.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importar empresas',
                ],
                'import_export' => [
                    'label' => 'Importar / Exportar',
                ],
                'export' => [
                    'label' => 'Exportar empresas',
                    'heading' => 'Exportar empresas',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'account_owner' => [
                        'label' => 'Responsable',
                    ],
                ],
            ],
            'activity_log' => [
                'description' => 'Toda la actividad de esta empresa, agrupada por semana.',
            ],
        ],
    ],
];
