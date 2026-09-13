<?php

declare(strict_types=1);

return [
    'title' => 'Notificaciones',

    'digest' => [
        'heading' => 'Resumen diario',
        'title' => 'Activar el resumen diario',
        'description' => 'Incluye las tareas vencidas y las que vencen hoy. Se envía cada mañana si hay tareas que vencen hoy o ya vencidas.',
    ],

    'collaboration' => [
        'heading' => 'Notificaciones de colaboración',
        'notify_me_about' => 'Avisarme de',
    ],

    'channels' => [
        'in_app' => 'App',
        'email' => 'Correo',
    ],

    'types' => [
        'task_assigned' => [
            'label' => 'Asignación de tareas',
            'description' => 'Avisarme cuando me asignen una tarea.',
        ],
        'task_digest' => [
            'label' => 'Resumen diario',
            'description' => 'Avisarme cada mañana de las tareas vencidas y las que vencen hoy.',
        ],
    ],

    'saved' => 'Preferencias de notificación actualizadas.',
];
