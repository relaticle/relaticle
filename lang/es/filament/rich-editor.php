<?php

declare(strict_types=1);

return [
    // `:key` se sustituye por el carácter que abre el menú, mostrado como tecla.
    'placeholder' => 'Escribe :key para insertar un título, una lista o una imagen',

    'limit_reached' => 'Este campo está lleno. Borra parte del contenido para seguir escribiendo.',

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
                'label' => 'Título 1',
                'description' => 'Título de sección grande',
            ],
            'h2' => [
                'label' => 'Título 2',
                'description' => 'Título de sección mediano',
            ],
            'h3' => [
                'label' => 'Título 3',
                'description' => 'Título de sección pequeño',
            ],
            'paragraph' => [
                'label' => 'Texto normal',
                'description' => 'Empieza a escribir sin formato',
            ],
            'bulletList' => [
                'label' => 'Lista con viñetas',
                'description' => 'Crea una lista con viñetas',
            ],
            'orderedList' => [
                'label' => 'Lista numerada',
                'description' => 'Crea una lista numerada',
            ],
            'blockquote' => [
                'label' => 'Cita',
                'description' => 'Destaca un fragmento',
            ],
            'codeBlock' => [
                'label' => 'Código',
                'description' => 'Inserta un fragmento de código',
            ],
            'table' => [
                'label' => 'Tabla',
                'description' => 'Organiza datos en filas',
            ],
            'details' => [
                'label' => 'Desplegable',
                'description' => 'Oculta detalles tras un resumen',
            ],
            'horizontalRule' => [
                'label' => 'Separador',
                'description' => 'Separa secciones con una línea',
            ],
            'attachFiles' => [
                'label' => 'Imagen',
                'description' => 'Sube una imagen',
            ],
        ],
    ],
];
