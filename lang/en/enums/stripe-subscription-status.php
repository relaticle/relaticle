<?php

declare(strict_types=1);

return [
    'active' => 'Active',
    'trialing' => 'Trialing',
    'past_due' => 'Past due',
    'unpaid' => 'Unpaid',
    'canceled' => 'Canceled',
    'incomplete' => 'Incomplete',
    'incomplete_expired' => 'Incomplete expired',
    'paused' => 'Paused',
    'descriptions' => [
        'active' => 'Paid and current. The latest invoice was charged successfully.',
        'trialing' => 'Inside a Stripe trial. Nothing has been charged yet.',
        'past_due' => 'The latest invoice failed. Stripe is retrying, and access stays open until it gives up.',
        'unpaid' => 'Stripe exhausted its retries and stopped trying. Access is gone.',
        'canceled' => 'Ended. No further invoices will be raised.',
        'incomplete' => 'The first payment never completed, so the subscription never started.',
        'incomplete_expired' => 'The first payment was abandoned and Stripe expired the attempt.',
        'paused' => 'Paused at the end of a trial that collected no payment method.',
    ],
];
