<?php

declare(strict_types=1);

return [
    'label' => 'persona',
    'plural_label' => 'personas',
    'navigation_label' => 'Personas',

    'fields' => [
        'name' => [
            'label' => 'Persona',
        ],
        'company' => [
            'label' => 'Empresa',
        ],
        'company_id' => [
            'label' => 'Empresa',
        ],
        'account_owner_id' => [
            'label' => 'Responsable',
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

    'empty_state' => [
        'description' => 'Las personas que agregue o importe aparecen aquí.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importar personas',
                ],
                'import_export' => [
                    'label' => 'Importar / Exportar',
                ],
                'export' => [
                    'label' => 'Exportar personas',
                    'heading' => 'Exportar personas',
                ],
                'create_company' => [
                    'label' => 'Crear empresa',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Empresa',
                    ],
                ],
            ],
        ],
    ],
];
