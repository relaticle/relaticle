<?php

declare(strict_types=1);

return [
    // Filament pone en mayúscula el singular/plural para contextos de visualización
    // (menú de navegación, encabezados de página) pero los inyecta tal cual en el
    // botón "Nueva :label". Se mantienen en minúsculas para que el botón diga
    // "Nueva empresa" mientras los títulos muestren "Empresa"/"Empresas".
    'label' => 'empresa',
    'plural_label' => 'empresas',
    'navigation_label' => 'Empresas',

    'fields' => [
        'name' => [
            'label' => 'Empresa',
        ],
        'account_owner' => [
            'label' => 'Responsable de cuenta',
        ],
        'account_owner_id' => [
            'label' => 'Responsable de cuenta',
        ],
        'created_by' => [
            'label' => 'Creado por',
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
                    'logo' => [
                        'label' => '',
                    ],
                    'creator' => [
                        'label' => 'Creado por',
                    ],
                    'account_owner' => [
                        'label' => 'Responsable de cuenta',
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
            'model_label' => 'persona',
        ],
        'notes' => [
            'fields' => [
                'people' => [
                    'label' => 'Personas',
                ],
            ],
        ],
        'tasks' => [
            'fields' => [
                'assignees' => [
                    'label' => 'Asignado a',
                ],
                'people' => [
                    'label' => 'Personas',
                ],
                'created_at' => [
                    'label' => 'Fecha de creación',
                ],
            ],
        ],
    ],
];
