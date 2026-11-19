<?php

declare(strict_types=1);

return [
    'fields' => [
        'name' => [
            'label' => 'Nombre de la plantilla',
        ],
        'subject' => [
            'label' => 'Asunto',
        ],
        'body_html' => [
            'label' => 'Cuerpo',
        ],
        'is_shared' => [
            'label' => 'Compartir con el espacio de trabajo',
            'helper_text' => 'Todos los miembros de su espacio de trabajo pueden usar esta plantilla.',
        ],
    ],

    'columns' => [
        'subject' => [
            'placeholder' => '—',
        ],
        'is_shared' => [
            'label' => 'Compartida',
        ],
        'creator' => [
            'label' => 'Creada por',
            'placeholder' => '—',
        ],
        'created_at' => [
            'label' => 'Creada',
        ],
    ],

    'empty' => [
        'heading' => 'No hay plantillas',
        'description' => 'Guarde un mensaje que envíe con frecuencia para reutilizarlo.',
    ],

    'actions' => [
        'create' => [
            'label' => 'Nueva plantilla',
        ],
        'edit' => [
            'label' => 'Editar plantilla',
        ],
        'delete' => [
            'label' => 'Eliminar',
        ],
    ],
];
