<?php

declare(strict_types=1);

return [
    'consent' => [
        'title' => 'Autorizar :client',
        'intro' => ':client quiere conectarse a tu espacio de trabajo de Crabdev CRM.',
        'signed_in_as' => 'Has iniciado sesión como',

        'workspace' => [
            'heading' => '¿Qué espacio de trabajo?',
            'description' => ':client solo verá los datos del espacio de trabajo que elijas. Para usar otro más adelante, revoca este conector en la página de tokens de acceso de Crabdev CRM y vuelve a añadirlo.',
            'aria_label' => 'Selección de espacio de trabajo',
            'personal' => 'Personal',
            'paused' => 'En pausa. Suscríbete para conectar',
            'all_paused' => 'Todos los espacios de trabajo de esta cuenta están en pausa. Suscríbete a Cloud Pro antes de conectar. Un conector autorizado en un espacio de trabajo en pausa no puede leer ni escribir datos.',
            'none' => [
                'heading' => 'No perteneces a ningún espacio de trabajo.',
                'description' => 'Crea un espacio de trabajo o únete a uno en Crabdev CRM antes de autorizar este conector.',
            ],
        ],

        'permissions' => [
            'heading' => 'Qué podrá hacer',
            'description' => 'En el espacio de trabajo de arriba y en ningún otro.',
            'read' => [
                'title' => 'Leer y buscar tus registros',
                'description' => 'Empresas, contactos, oportunidades, tareas y notas.',
            ],
            'write' => [
                'title' => 'Crearlos y actualizarlos',
                'description' => 'Añadir registros, cambiar campos y vincularles notas y tareas.',
            ],
            'delete' => [
                'title' => 'Eliminarlos',
                'description' => 'Eliminar un registro es permanente.',
            ],
            'excluded' => 'No puede acceder a tus otros espacios de trabajo, a los miembros del equipo, a la facturación ni a los ajustes de la cuenta.',
        ],

        'actions' => [
            'cancel' => 'Cancelar',
            'authorize' => 'Autorizar',
            'authorizing' => 'Autorizando...',
        ],

        'revoke_hint' => 'Puedes revocar este conector cuando quieras desde la página de tokens de acceso de Crabdev CRM.',
    ],
];
