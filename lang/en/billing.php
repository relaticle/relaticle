<?php

declare(strict_types=1);

return [
    'title' => 'Billing',
    'plans' => [
        'free' => 'Free',
        'pro' => 'Pro',
        'enterprise' => 'Enterprise',
    ],
    'usage' => [
        'title' => 'AI credits this period',
        'of' => ':used of :allowance used',
        'resets' => 'Resets :date',
    ],
    'trial' => [
        'start_button' => 'Start 14-day Pro trial — no card needed',
        'active_title' => 'Pro trial active',
        'days_left' => ':days day left|:days days left',
        'started' => 'Your Pro trial is active — enjoy!',
    ],
    'upgrade' => [
        'button' => 'Upgrade to Pro',
        'monthly' => '$29 / month',
        'yearly' => '$290 / year — 2 months free',
        'activating' => 'Payment received — activating Pro…',
    ],
    'subscribe' => [
        'button' => 'Subscribe now',
    ],
    'manage' => [
        'button' => 'Manage subscription',
        'renews' => 'Renews :date',
        'cancel_scheduled_title' => 'Cancellation scheduled',
        'cancel_scheduled_body' => 'Pro stays active until :date. You can resume from the portal.',
        'past_due_title' => 'Payment issue',
        'past_due_body' => 'Your last payment failed. Update your payment method to keep Pro.',
    ],
    'enterprise' => [
        'title' => 'Enterprise plan',
        'body' => 'Your plan is managed by Relaticle. Contact us for changes.',
    ],
    'member' => [
        'ask_owner' => 'Billing is managed by :owner, the workspace owner.',
    ],
    'errors' => [
        'checkout_failed' => "We couldn't start checkout just now. Please try again in a moment.",
    ],
    'deletion_notice' => 'Any active Pro subscription is canceled — Pro stays until the end of the paid period.',
];
