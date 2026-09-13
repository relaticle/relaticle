<?php

declare(strict_types=1);

return [
    // Filament pone en mayúscula inicial las etiquetas en navegación y títulos,
    // pero las inserta tal cual en el botón "Crear :label". Van en minúscula
    // para que el botón diga "Crear empresa" y los títulos "Empresa"/"Empresas".
    'label' => 'empresa',
    'plural_label' => 'empresas',
    'navigation_label' => 'Empresas',

    'fields' => [
        'name' => [
            'label' => 'Empresa',
        ],
        'account_owner' => [
            'label' => 'Responsable de la cuenta',
        ],
        'account_owner_id' => [
            'label' => 'Responsable de la cuenta',
        ],
        'created_by' => [
            'label' => 'Creado por',
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
                    'label' => 'Importar empresas',
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
                    'creator' => [
                        'label' => 'Creado por',
                    ],
                    'account_owner' => [
                        'label' => 'Responsable de la cuenta',
                    ],
                    'created_at' => [
                        'label' => 'Fecha de creación',
                    ],
                    'updated_at' => [
                        'label' => 'Última actualización',
                    ],
                ],
            ],
        ],
    ],

    'relation_managers' => [
        'people' => [
            'model_label' => 'contacto',
        ],
        'notes' => [
            'fields' => [
                'people' => [
                    'label' => 'Contactos',
                ],
            ],
        ],
        'tasks' => [
            'fields' => [
                'assignees' => [
                    'label' => 'Asignada a',
                ],
                'people' => [
                    'label' => 'Contactos',
                ],
                'created_at' => [
                    'label' => 'Fecha de creación',
                ],
            ],
        ],
    ],
];
