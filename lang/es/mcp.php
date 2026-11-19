<?php

declare(strict_types=1);

return [
    'connection' => [
        'fallback_name' => 'Un asistente de IA',
    ],

    'consent' => [
        'title' => 'Autorizar a :client',
        'intro' => [
            'one' => ':client solicita conectarse a :workspace.',
            'choose' => ':client solicita conectarse a uno de sus espacios de trabajo.',
        ],
        'redirect' => 'Después de autorizar, volverá a :host. Continúe solo si confía en ese sitio.',
        'signed_in_as' => 'Sesión iniciada como :email.',
        'invalid_resource' => 'Esta conexión solicitó acceso a un servidor distinto. Relaticle solo autoriza conexiones a su propio MCP server.',

        'workspace' => [
            'heading' => '¿Qué espacio de trabajo?',
            'description' => 'Para usar otro más adelante, revoque este conector y agréguelo de nuevo.',
            'aria_label' => 'Selección de espacio de trabajo',
            'personal' => 'Personal',
            'paused' => 'En pausa. Suscríbase para conectar',
            'all_paused' => 'Todos los espacios de trabajo de esta cuenta están en pausa. Suscríbase a un plan de Cloud antes de conectar. Un conector autorizado en un espacio de trabajo en pausa no puede leer ni escribir datos.',
            'none' => [
                'heading' => 'No pertenece a ningún espacio de trabajo.',
                'description' => 'Cree un espacio de trabajo o únase a uno en Relaticle antes de autorizar este conector.',
            ],
        ],

        'permissions' => [
            'heading' => ':client podrá',
            'read' => 'Leer y buscar sus registros',
            'write' => 'Crearlos y actualizarlos',
            'delete' => 'Eliminarlos. Puede restaurar los registros eliminados.',
            'email_read' => 'Leer el correo que usted puede ver en este espacio de trabajo',
            'email_draft' => 'Guardar borradores de correo para que usted los revise',
            'email_send' => 'Enviar correo en su nombre, tras una espera que puede cancelar',
            'none' => 'Su rol en este espacio de trabajo no permite nada de lo que :client solicitó.',
            'excluded' => 'No puede acceder a sus otros espacios de trabajo, a los miembros del espacio de trabajo, a la facturación ni a la configuración de la cuenta.',
        ],

        'actions' => [
            'cancel' => 'Cancelar',
            'authorize' => 'Autorizar',
            'authorizing' => 'Autorizando...',
        ],

        'revoke_hint' => 'Revoque este conector en cualquier momento desde la página de tokens de acceso.',
    ],
];
