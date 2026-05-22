<?php

declare(strict_types=1);

return [
    // Singular/plural en minúsculas para que el botón "Nueva :label" se lea con naturalidad.
    'label' => 'oportunidad',
    'plural_label' => 'oportunidades',
    'navigation_label' => 'Oportunidades',

    'fields' => [
        'name' => [
            'label' => 'Oportunidad',
            'placeholder' => 'Introduce el título de la oportunidad',
        ],
        'company_id' => [
            'label' => 'Empresa',
        ],
        'contact_id' => [
            'label' => 'Persona de contacto',
        ],
        'creator' => [
            'label' => 'Creado por',
        ],
        'creation_source' => [
            'label' => 'Fuente de creación',
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

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importar oportunidades',
                ],
                'import_export' => [
                    'label' => 'Importar / Exportar',
                ],
            ],
        ],
        'view' => [
            'actions' => [
                'edit' => [
                    'label' => 'Editar',
                ],
                'copy_page_url' => [
                    'label' => 'Copiar URL de la página',
                ],
                'copy_record_id' => [
                    'label' => 'Copiar ID del registro',
                ],
            ],
            'infolist' => [
                'fields' => [
                    'name' => [
                        'label' => '',
                    ],
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
