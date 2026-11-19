<?php

declare(strict_types=1);

return [
    'actions' => [
        'compose' => [
            'label' => 'Redactar',
            'tooltip' => 'Atajo de teclado: C',
        ],
        'compose_email' => [
            'label' => 'Redactar correo electrónico',
        ],
        'undo' => [
            'label' => 'Deshacer',
        ],
        'cancel_send' => [
            'label' => 'Cancelar envío',
        ],
    ],
    'notifications' => [
        'queued' => [
            'title' => 'Correo electrónico en cola',
            'body' => 'Su correo electrónico se está enviando.',
        ],
        'cancelled' => [
            'title' => 'Envío cancelado',
        ],
        'held' => [
            'title' => ':via puso un correo electrónico en cola',
            'rescheduled_title' => ':via reprogramó un correo electrónico',
            'body' => '":subject" se enviará a :recipients en :minutes. Espacio de trabajo: :workspace. Cancélelo si no lo solicitó.',
            'scheduled_body' => '":subject" se enviará a :recipients el :date. Espacio de trabajo: :workspace. Cancélelo si no lo solicitó.',
            'sent_body' => '":subject" se envió a :recipients. Espacio de trabajo: :workspace.',
            'cancelled_body' => '":subject" para :recipients se canceló y no se envió. Espacio de trabajo: :workspace.',
            'failed_body' => '":subject" para :recipients no se pudo enviar. Espacio de trabajo: :workspace.',
            'sending_body' => '":subject" se está enviando a :recipients. Espacio de trabajo: :workspace.',
            'waiting_body' => '":subject" para :recipients aguarda su envío. Espacio de trabajo: :workspace. Cancélelo si no lo solicitó.',
            'minutes' => ':count minuto|:count minutos',
        ],
        'too_late' => [
            'title' => 'Demasiado tarde, el correo electrónico ya se envió',
        ],
        'not_found' => [
            'title' => 'No se encontró ese correo electrónico',
        ],
    ],
    'fields' => [
        'template' => [
            'label' => 'Plantilla',
            'placeholder' => 'Aplicar una plantilla…',
        ],
        'body' => [
            'label' => 'Cuerpo',
        ],
        'scheduled_for' => [
            'label' => 'Enviar a las',
            'helper_text' => 'Déjelo en blanco para enviar con 5 segundos para deshacer.',
        ],
        'signature' => [
            'label' => 'Firma',
            'placeholder' => 'Sin firma',
        ],
    ],
    'sections' => [
        'settings' => [
            'description' => 'Opciones de privacidad, programación y firma para este correo electrónico.',
        ],
    ],
];
