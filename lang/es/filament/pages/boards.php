<?php

declare(strict_types=1);

return [
    'view_switcher' => [
        'board' => 'Tablero',
        'cards' => 'Tarjetas',
        'opportunity_board' => 'Pipeline',
    ],

    'stage_order_failed' => 'No se pudo guardar el orden de las etapas.',

    'view_settings' => [
        'panel' => 'Configuración de la vista del tablero',
        'stage_actions' => 'Acciones para :stage',
        'grouped_by' => 'Agrupado por',
        'lanes' => 'Carriles',
        'no_lanes' => 'Sin carriles',
        'no_lanes_short' => 'Ninguno',
        'visible_columns' => 'Columnas visibles',
        'show_column' => 'Mostrar :column',
        'last_column' => 'Una columna debe permanecer visible.',
        'no_value_column' => 'Sin :attribute',
        'show_labels' => 'Mostrar etiquetas de atributos',
        'hide_empty' => 'Ocultar atributos vacíos',
        'visible_attributes' => 'Atributos visibles',
        'attribute_actions' => 'Acciones para :attribute',
        'remove_attribute' => 'Quitar de la tarjeta',
        'attribute_created_off_card' => 'Se creó :name. Este tipo de atributo no se muestra en una tarjeta.',
        'columns_grouped_by_created' => 'Se creó :name. Las columnas ahora se agrupan por él.',
        'add_attribute' => 'Agregar atributo a la vista',
        'search_attributes' => 'Buscar atributos de :entity…',
        'all_attributes_shown' => 'Todos los atributos están en la tarjeta',
        'no_attribute_matches' => 'Ningún atributo coincide',
    ],

    'card_attributes' => [
        'description' => 'Descripción',
        'checklist' => 'Lista de verificación',
        'empty' => 'Vacío',
    ],

    'stage_menu' => [
        'nouns' => [
            'stage' => 'etapa',
            'status' => 'estado',
            'option' => 'opción',
        ],
        'add' => 'Agregar columna (:noun)',
        'add_placeholder' => 'Nombre de la nueva columna (:noun)',
        'name_taken' => 'Ya existe ":name" (:noun).',
        'collapse' => 'Contraer columna (:noun)',
        'hide' => 'Ocultar columna (:noun)',
        'name' => 'Nombre de la columna (:noun)',
        'color' => 'Cambiar el color de la columna (:noun)',
        'set_color' => 'Establecer el color en :color',
        'confetti' => 'Confeti',
        'confetti_label' => 'Lanzar confeti cuando una tarjeta llegue a esta columna (:noun)',
        'last_visible' => 'Una columna (:noun) debe permanecer visible. Muestre otra antes de ocultar esta.',
        'delete' => 'Eliminar columna (:noun)',
        'delete_heading' => '¿Eliminar ":name"?',
        'delete_body' => '{1} :count :records quedará sin :attribute. Se muestra en la columna :column cuando está activada en la configuración de la vista.|[2,*] :count :records quedarán sin :attribute. Se muestran en la columna :column cuando está activada en la configuración de la vista.',
        'changed' => 'La lista (:noun) cambió mientras la editaba. Recargue el tablero e inténtelo de nuevo.',
        'delete_confirm' => 'Eliminar',
        'delete_only' => 'Un tablero necesita al menos una columna (:noun). Agregue otra antes de eliminar esta.',
        'delete_last_visible' => 'Una columna (:noun) debe permanecer visible. Muestre otra antes de eliminar esta.',
        'records' => [
            'opportunity' => 'oportunidad|oportunidades',
            'task' => 'tarea|tareas',
        ],
    ],

    'layout' => [
        'columns' => 'Columnas',
        'card_size' => 'Tamaño de la tarjeta',
        'columns_collapsed' => 'Columnas contraídas',
        'columns_expanded' => 'Columnas expandidas',
        'compact' => 'Tarjetas compactas',
        'full' => 'Tarjetas completas',
    ],

    'move_denied' => 'No tiene permiso para mover esta tarjeta.',

    'sorted_no_reorder' => 'Las tarjetas están ordenadas por :field. Quite el orden para organizarlas a mano.',

    'move_to' => [
        'label' => 'Mover a',
        'moved' => 'Movida a :column',
    ],

    'opportunities' => [
        'title' => 'Oportunidades',
        'actions' => [
            'add' => 'Agregar oportunidad',
            'edit' => 'Editar',
            'delete' => 'Eliminar',
        ],
        'form' => [
            'name_placeholder' => 'Ingrese el título de la oportunidad',
        ],
        'badges' => [
            'today' => 'Cierra hoy',
            'tomorrow' => 'Cierra mañana',
            'overdue' => ':date (vencida)',
        ],
        'groupings' => [
            'company' => 'Empresa',
            'no_company' => 'Sin empresa',
        ],
    ],

    'tasks' => [
        'title' => 'Tareas',
        'actions' => [
            'add' => 'Agregar tarea',
            'edit' => 'Editar',
            'delete' => 'Eliminar',
        ],
        'badges' => [
            'today' => 'Vence hoy',
            'tomorrow' => 'Vence mañana',
            'overdue' => ':date (vencida)',
        ],
        'groupings' => [
            'assignee' => 'Asignado',
            'unassigned' => 'Sin asignar',
        ],
        'checklist_progress' => 'Progreso de la lista de verificación',
        'checklist_progress_tooltip' => '{1} :checked de :total elemento completado|[2,*] :checked de :total elementos completados',
    ],
];
