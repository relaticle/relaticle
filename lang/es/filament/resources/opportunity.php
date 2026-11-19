<?php

declare(strict_types=1);

return [
    'label' => 'oportunidad',
    'plural_label' => 'oportunidades',
    'navigation_label' => 'Oportunidades',

    'fields' => [
        'name' => [
            'label' => 'Oportunidad',
            'placeholder' => 'Ingrese el título de la oportunidad',
        ],
        'company_id' => [
            'label' => 'Empresa',
        ],
        'contact_id' => [
            'label' => 'Persona de contacto',
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
        'description' => 'Las oportunidades que agregue o importe aparecen aquí.',
    ],

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importar oportunidades',
                ],
                'import_export' => [
                    'label' => 'Importar / Exportar',
                ],
                'export' => [
                    'label' => 'Exportar oportunidades',
                    'heading' => 'Exportar oportunidades',
                ],
            ],
        ],
        'view' => [
            'infolist' => [
                'fields' => [
                    'company' => [
                        'label' => 'Empresa',
                    ],
                    'contact' => [
                        'label' => 'Persona de contacto',
                    ],
                ],
            ],
        ],
    ],
];
