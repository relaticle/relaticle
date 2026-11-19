<?php

declare(strict_types=1);

return [
    'title' => 'Configure su espacio de trabajo',
    'opens_in_new_tab' => '(se abre en una pestaña nueva)',
    'assistant_prompt' => 'Traslade mis clientes actuales a Relaticle: agregue cada empresa y las personas con las que trabajo allí, y una oportunidad por cada venta que siga abierta.',
    'buttons' => [
        'add_client' => [
            'label' => 'Agregar un cliente',
            'prompt' => 'Quiero agregar una empresa con la que trabajo y las personas que hay allí. Pregúnteme los detalles.',
        ],
        'show_synced' => [
            'label' => 'Mostrar mis empresas sincronizadas',
            'prompt' => 'Muéstreme las empresas que se sincronizaron desde mi buzón.',
        ],
        'add_outside_email' => [
            'label' => 'Agregar a alguien que no está en mi correo',
            'prompt' => 'Quiero agregar una persona o empresa que no está en mi correo electrónico. Pregúnteme los detalles.',
        ],
        'upload_file' => [
            'label' => 'Subir mi archivo',
        ],
        'connect_assistant' => [
            'label' => 'Conectar Claude o ChatGPT',
            'prompt' => '¿Cómo conecto Claude o ChatGPT a este espacio de trabajo?',
        ],
        'pick_first' => [
            'label' => 'Elegir con quién empezar',
            'prompt' => 'Muéstreme las empresas que acaban de llegar para elegir con cuál empezar.',
        ],
        'later' => [
            'label' => 'Más tarde',
            'prompt' => 'Elegiré con quién empezar más tarde.',
        ],
    ],
];
