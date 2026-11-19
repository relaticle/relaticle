<?php

declare(strict_types=1);

return [
    // `:key` is replaced with the trigger character, rendered as a key cap.
    'placeholder' => 'Escriba :key para insertar un encabezado, una lista o una imagen',

    'limit_reached' => 'Este campo está lleno. Elimine contenido para seguir escribiendo.',

    'checklist' => [
        'item' => 'Elemento de la lista de verificación: :item',
        'empty_item' => 'Elemento vacío de la lista de verificación',
    ],

    'selection_toolbar' => [
        'text_style' => 'Estilo de texto',
    ],

    'slash_menu' => [
        'no_results' => 'Ningún bloque coincide con ":query"',

        'groups' => [
            'text' => 'Texto',
            'lists' => 'Listas',
            'insert' => 'Insertar',
        ],

        'items' => [
            'h1' => [
                'label' => 'Encabezado 1',
                'description' => 'Encabezado de sección grande',
            ],
            'h2' => [
                'label' => 'Encabezado 2',
                'description' => 'Encabezado de sección mediano',
            ],
            'h3' => [
                'label' => 'Encabezado 3',
                'description' => 'Encabezado de sección pequeño',
            ],
            'paragraph' => [
                'label' => 'Cuerpo',
                'description' => 'Empiece a escribir con texto sin formato',
            ],
            'bulletList' => [
                'label' => 'Lista con viñetas',
                'description' => 'Cree una lista sencilla con viñetas',
            ],
            'orderedList' => [
                'label' => 'Lista numerada',
                'description' => 'Cree una lista con numeración',
            ],
            'taskList' => [
                'label' => 'Lista de verificación',
                'description' => 'Siga los pasos con casillas',
            ],
            'blockquote' => [
                'label' => 'Cita',
                'description' => 'Destaque un pasaje',
            ],
            'codeBlock' => [
                'label' => 'Código',
                'description' => 'Guarde un fragmento',
            ],
            'table' => [
                'label' => 'Tabla',
                'description' => 'Organice datos en filas',
            ],
            'details' => [
                'label' => 'Desplegable',
                'description' => 'Oculte el detalle tras un resumen',
            ],
            'horizontalRule' => [
                'label' => 'Divisor',
                'description' => 'Separe secciones con una línea',
            ],
            'attachFiles' => [
                'label' => 'Imagen',
                'description' => 'Suba una imagen',
            ],
        ],
    ],
];
