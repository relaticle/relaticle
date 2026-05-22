<?php

declare(strict_types=1);

return [
    // Singular/plural en minúsculas para que el botón "Nueva :label" se lea con naturalidad.
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
            'label' => 'Responsable de cuenta',
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
                    'label' => 'Importar personas',
                ],
                'import_export' => [
                    'label' => 'Importar / Exportar',
                ],
                'create_company' => [
                    'label' => 'Crear empresa',
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
                    'avatar' => [
                        'label' => '',
                    ],
                    'name' => [
                        'label' => '',
                    ],
                    'company' => [
                        'label' => 'Empresa',
                    ],
                ],
            ],
        ],
    ],
];
