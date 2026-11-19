<?php

declare(strict_types=1);

return [
    'past_due' => 'Pago vencido',
    'subscribed' => 'Suscrito',
    'enterprise' => 'Enterprise',
    'trialing' => 'Prueba',
    'grandfathered' => 'Gratuito (histórico)',
    'subscription_ended' => 'Suscripción finalizada',
    'trial_ended' => 'Prueba finalizada',
    'granted' => 'Otorgado',
    'free' => 'Gratuito',
    'descriptions' => [
        'past_due' => 'Suscripción de pago cuyo último cargo falló. El acceso sigue abierto hasta que Stripe la cancele.',
        'subscribed' => 'Paga Cloud Pro o Cloud Scale mediante una suscripción activa de Stripe.',
        'enterprise' => 'Asignado manualmente al plan Enterprise. No existe un precio de autoservicio para este plan.',
        'trialing' => 'Tiene una prueba de Pro vigente. Todavía no se ha cobrado nada.',
        'grandfathered' => 'Existía antes de que se lanzara la facturación, por lo que el acceso alojado seguirá siendo gratuito para siempre.',
        'subscription_ended' => 'La suscripción de pago terminó y no hay nada que la respalde. El acceso alojado está en pausa hasta que el propietario vuelva a suscribirse.',
        'trial_ended' => 'La prueba de Pro venció sin suscripción. El acceso alojado está en pausa hasta que el propietario se suscriba.',
        'granted' => 'Se le asignó un plan de pago manualmente, sin suscripción ni prueba que lo respalde.',
        'free' => 'Sin suscripción, prueba ni condición histórica. El acceso alojado está en pausa mientras la facturación esté activada.',
    ],
];
