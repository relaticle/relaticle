<?php

declare(strict_types=1);

return [
    'notice' => [
        'subject' => 'Ahora eliminamos los espacios de trabajo de prueba en pausa después de 60 días',
        'preheader' => ':workspace está a salvo hasta el :date. Elija un plan o inicie sesión para conservarlo más tiempo.',
        'heading' => 'Ahora eliminamos los espacios de trabajo de prueba en pausa después de 60 días',
        'body' => ':workspace está en pausa desde que terminó su prueba. A partir de hoy, eliminamos un espacio de trabajo de prueba en pausa después de 60 días sin inicios de sesión.',
        'date' => 'Sus 60 días comienzan hoy. Si nadie inicia sesión y nadie elige un plan, eliminaremos :workspace el :date.',
        'warn' => 'Le enviaremos un correo :warning días y :reminder días antes de esa fecha.',
        'cta' => 'Elegir un plan',
    ],
    'warning' => [
        'subject' => ':workspace se eliminará el :date',
        'preheader' => 'Nadie ha iniciado sesión desde el :since. Elija un plan o inicie sesión para conservarlo.',
        'heading' => ':workspace se eliminará el :date',
        'paused' => ':workspace está en pausa y nadie ha iniciado sesión desde el :since.',
        'rule' => 'Eliminamos un espacio de trabajo de prueba en pausa después de 60 días sin inicios de sesión. El :date eliminaremos :workspace y todos los registros que contiene. Esta acción no se puede deshacer.',
        'keep' => 'Para conservar el espacio de trabajo, elija un plan antes de esa fecha.',
        'cta' => 'Elegir un plan',
        'sign_in' => 'Iniciar sesión también lo conserva por otros 60 días.',
    ],
    'reminder' => [
        'subject' => ':workspace se eliminará el :date',
        'preheader' => 'Este es el último recordatorio. Elija un plan o inicie sesión para conservarlo.',
        'heading' => ':workspace se eliminará el :date',
        'body' => 'Eliminaremos :workspace y todos los registros que contiene el :date. Esta acción no se puede deshacer.',
        'keep' => 'Elija un plan o inicie sesión antes de esa fecha para conservarlo.',
    ],
    'deleted' => [
        'subject' => ':workspace se eliminó',
        'preheader' => 'Estuvo en pausa durante 60 días sin inicios de sesión.',
        'heading' => ':workspace se eliminó',
        'body' => ':workspace estuvo en pausa durante 60 días sin inicios de sesión, por lo que lo eliminamos junto con sus registros.',
        'restart' => 'Puede iniciar sesión en cualquier momento y crear un nuevo espacio de trabajo.',
    ],
];
