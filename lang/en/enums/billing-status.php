<?php

declare(strict_types=1);

return [
    'past_due' => 'Past due',
    'subscribed' => 'Subscribed',
    'enterprise' => 'Enterprise',
    'trialing' => 'Trial',
    'grandfathered' => 'Free (legacy)',
    'subscription_ended' => 'Subscription ended',
    'trial_ended' => 'Trial ended',
    'granted' => 'Granted',
    'free' => 'Free',
    'descriptions' => [
        'past_due' => 'Paid subscription whose latest charge failed. Access stays open until Stripe cancels it.',
        'subscribed' => 'Paying for Cloud Pro or Cloud Scale through a live Stripe subscription.',
        'enterprise' => 'Put on the Enterprise plan by hand. No self-serve price exists for it.',
        'trialing' => 'Running an unexpired Pro trial. Nothing has been charged yet.',
        'grandfathered' => 'Existed before billing shipped, so hosted access stays free for good.',
        'subscription_ended' => 'Paid subscription ended with nothing behind it. Hosted access is paused until the owner subscribes again.',
        'trial_ended' => 'Pro trial expired with no subscription. Hosted access is paused until the owner subscribes.',
        'granted' => 'Given a paid plan by hand, with no subscription or trial behind it.',
        'free' => 'No subscription, trial, or grandfathering. Hosted access is paused while billing is on.',
    ],
];
