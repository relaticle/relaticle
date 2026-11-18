<?php

declare(strict_types=1);

return [
    'heading' => [
        'title' => 'Atributos personalizados',
        'description' => 'Crea y gestiona atributos personalizados para registrar datos adicionales en tus registros.',
    ],
    'nav' => [
        'label' => 'Atributos personalizados',
        'group' => 'Atributos personalizados',
    ],
    'section' => [
        'form' => [
            'contains_system_fields_cannot_delete' => 'Las secciones que contienen atributos definidos por el sistema no se pueden eliminar.',
        ],
        'notifications' => [
            'duplicate_field_code' => 'Esta sección ya tiene un atributo con ese código. Cambia el nombre de uno de los dos antes de moverlo aquí.',
        ],
    ],
    'field' => [
        'form' => [
            'name_helper_text' => 'La etiqueta del atributo que se muestra en tablas y formularios.',
            'code_helper_text' => 'Código único para identificar este atributo en todo el recurso.',
            'description_position_options' => [
                'below' => 'Debajo del atributo',
                'above' => 'Encima del atributo',
            ],
            'encrypted_help' => 'Si está activado, los valores de este atributo se guardarán cifrados.',
            'visible_in_list_help' => 'Mostrar este atributo en las listas de las tablas.',
            'list_toggleable_hidden_hint' => 'Si está activado, este atributo estará oculto de forma predeterminada en la vista de lista, pero cada persona puede mostrarlo.',
            'visible_in_view_help' => 'Mostrar este atributo en las páginas de detalle.',
            'add_field' => 'Añadir atributo',
            'search_placeholder' => 'Buscar atributos...',
            'type_search_prompt' => 'Buscar tipos de atributo...',
            'type_no_results' => 'No se encontraron tipos de atributo',
            'system_defined_cannot_delete' => 'Los atributos definidos por el sistema no se pueden eliminar.',
            'allow_multiple_help' => 'Si está activado, se pueden introducir varios valores para este atributo.',
        ],
        'actions' => [
            'edit_modal_heading' => 'Editar atributo',
            'delete_modal_heading' => 'Eliminar atributo',
            'delete_modal_description' => '¿Seguro que quieres eliminar este atributo? Esta acción no se puede deshacer.',
        ],
    ],
    'empty_states' => [
        'sections' => [
            'description' => 'Crea tu primera sección para organizar y agrupar tus atributos personalizados.',
        ],
        'fields' => [
            'description' => 'Arrastra atributos aquí o haz clic en el botón de abajo para añadir tu primer atributo.',
        ],
        'fields_no_sections' => [
            'heading' => 'Aún no hay atributos personalizados',
            'description' => 'Haz clic en el botón de abajo para añadir tu primer atributo personalizado.',
        ],
        'search_no_results' => [
            'heading' => 'No hay atributos que coincidan',
            'description' => 'Ningún atributo coincide con tu búsqueda.',
        ],
    ],
    'common' => [
        'active_fields' => 'Atributos activos',
        'inactive_fields' => 'Atributos inactivos',
    ],
    'enums' => [
        'condition_source' => [
            'custom_field' => 'Atributo personalizado',
        ],
        'date_anchor' => [
            'custom_field' => 'Otro atributo',
        ],
        'description_position' => [
            'below' => 'Debajo del atributo',
            'above' => 'Encima del atributo',
        ],
        'entity_feature' => [
            'labels' => [
                'custom_fields' => 'Atributos personalizados',
            ],
            'descriptions' => [
                'custom_fields' => 'El tipo de registro puede tener atributos personalizados',
                'lookup_source' => 'El tipo de registro puede usarse como fuente de búsqueda para atributos de selección',
                'scoped_management' => 'Los atributos personalizados del tipo de registro se gestionan por registro principal, no en la página de gestión global',
            ],
        ],
    ],
    'date_constraint' => [
        'reference_field' => 'Atributo de referencia',
    ],
];
