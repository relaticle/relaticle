<?php

declare(strict_types=1);

return [
    // Singular/plural en minúscula para que el botón "Crear :label" de Filament se lea bien.
    'label' => 'contacto',
    'plural_label' => 'contactos',
    'navigation_label' => 'Contactos',

    'fields' => [
        'name' => [
            'label' => 'Contacto',
        ],
        'company' => [
            'label' => 'Empresa',
        ],
        'company_id' => [
            'label' => 'Empresa',
        ],
        'account_owner_id' => [
            'label' => 'Responsable de la cuenta',
        ],
        'creator' => [
            'label' => 'Creado por',
        ],
        'creation_source' => [
            'label' => 'Origen de creación',
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

    'pages' => [
        'list' => [
            'actions' => [
                'import' => [
                    'label' => 'Importar contactos',
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
