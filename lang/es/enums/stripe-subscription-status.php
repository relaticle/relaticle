<?php

declare(strict_types=1);

return [
    'active' => 'Activa',
    'trialing' => 'En prueba',
    'past_due' => 'Pago vencido',
    'unpaid' => 'Sin pagar',
    'canceled' => 'Cancelada',
    'incomplete' => 'Incompleta',
    'incomplete_expired' => 'Incompleta vencida',
    'paused' => 'En pausa',
    'descriptions' => [
        'active' => 'Pagada y al día. La última factura se cobró correctamente.',
        'trialing' => 'Dentro de una prueba de Stripe. Todavía no se ha cobrado nada.',
        'past_due' => 'La última factura falló. Stripe está reintentando y el acceso sigue abierto hasta que desista.',
        'unpaid' => 'Stripe agotó sus reintentos y dejó de intentarlo. Se perdió el acceso.',
        'canceled' => 'Finalizada. No se emitirán más facturas.',
        'incomplete' => 'El primer pago nunca se completó, por lo que la suscripción nunca comenzó.',
        'incomplete_expired' => 'El primer pago se abandonó y Stripe dio por vencido el intento.',
        'paused' => 'En pausa al final de una prueba que no recopiló un método de pago.',
    ],
];
