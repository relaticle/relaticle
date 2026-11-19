<?php

declare(strict_types=1);

return [
    'title' => '{1}Correo no enviado|[2,*]:count correos no enviados',
    'partial_title' => 'Enviados: :sent. No enviados: :failed',
    'retry' => [
        'body_one' => '":subject" no se envió. Revíselo en la bandeja de salida, en Fallidos, e inténtelo de nuevo.',
        'body_many' => 'Revíselos en la bandeja de salida, en Fallidos, e inténtelo de nuevo.',
        'action' => 'Ver correos fallidos',
    ],
    'reconnect' => [
        'body_one' => '":subject" no se envió porque su buzón debe volver a conectarse. Vuelva a conectarlo y reintente el correo desde la bandeja de salida.',
        'body_many' => 'Su buzón debe volver a conectarse. Vuelva a conectarlo y reintente los correos desde la bandeja de salida.',
        'action' => 'Reconectar buzón',
    ],
    'subscribe' => [
        'body_one' => '":subject" no se envió porque :workspace está en pausa. Cuando se reabra el espacio de trabajo, reintente el correo desde la bandeja de salida.',
        'body_many' => 'No se enviaron porque :workspace está en pausa. Cuando se reabra el espacio de trabajo, reintente los correos desde la bandeja de salida.',
        'action' => 'Ver facturación',
    ],
    'reasons' => [
        'mailbox_needs_reconnect' => 'El buzón debe volver a conectarse antes de poder enviar este correo.',
        'workspace_paused' => 'El espacio de trabajo estaba en pausa cuando debía enviarse este correo, por lo que no se envió.',
        'agent_connection_revoked' => 'La conexión del asistente que puso este correo en cola se eliminó antes de la hora de envío, por lo que no se envió.',
        'agent_send_not_allowed' => 'Su rol en este espacio de trabajo ya no permite que un asistente envíe correos en su nombre, por lo que no se envió.',
    ],
];
