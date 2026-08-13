<?php

declare(strict_types=1);

return [
    'title' => 'Billing',
    'subtitle' => 'Manage Cloud access, billing, and AI usage for this workspace.',
    'plans' => [
        'pro' => 'Pro',
        'cloud_pro' => 'Cloud Pro',
        'legacy_free' => 'Legacy Cloud Free',
        'enterprise' => 'Enterprise',
    ],
    'status' => [
        'trialing' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Past due',
        'canceling' => 'Canceling',
        'paused' => 'Paused',
        'grandfathered' => 'Grandfathered',
        'managed' => 'Managed',
    ],
    'usage' => [
        'title' => 'AI credits this period',
        'resets' => 'Resets :date',
    ],
    'packs' => [
        'buy' => 'Buy :credits credits',
        'balance_split' => 'Includes :purchased purchased credits — they never expire',
        'buy_more' => 'Buy more credits',
        'fulfilling_title' => 'Payment received — credits are on their way',
        'fulfilling_body' => 'It can take a minute for your new credits to appear. Refresh this page if the balance below still looks unchanged shortly.',
    ],
    'trial' => [
        'start_button' => 'Start 14-day Pro trial — no card needed',
        'active_title' => 'Pro trial active',
        'days_left' => ':days day left|:days days left',
        'started' => 'Your Pro trial is active — enjoy!',
        'not_available' => 'This workspace is not eligible for another trial.',
    ],
    'legacy_free' => [
        'title' => 'Your existing Free access is protected',
        'tagline' => 'Grandfathered for this workspace',
        'body' => 'You can keep using this workspace on its original Cloud Free plan. New workspaces start with a 14-day Cloud Pro trial.',
    ],
    'paused' => [
        'title' => 'Cloud access is paused',
        'tagline' => 'Subscribe to reopen this workspace',
        'body' => 'Your records are safe. Cloud Pro restores the app, REST API, MCP server, and AI assistant as soon as checkout completes.',
        'data_title' => 'Your data is retained',
        'data_body' => 'Nothing has been deleted while access is paused.',
    ],
    'pro_plan' => [
        'tagline' => 'For teams that put AI to work',
        'per_workspace' => 'Per workspace. Never per seat.',
        'features' => [
            'Unlimited users and records',
            '2,000 AI credits / month',
            'All AI models, including premium',
            'REST API and 32-tool MCP server',
            'Email support',
        ],
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'yearly_save' => 'Save 21%',
        'billed_monthly' => 'Billed monthly · cancel anytime',
        'billed_yearly' => '$228 billed yearly · cancel anytime',
    ],
    'upgrade' => [
        'button' => 'Upgrade to Pro',
        'unlock' => 'Unlock workspace with Pro',
        'now' => 'Upgrade now instead',
        'activating' => 'Payment received — activating Pro…',
        'activation_delayed_title' => 'Activation is taking longer than usual',
        'activation_delayed_body' => 'Your payment went through. Reload this page in a few minutes, and contact support if Pro still is not active.',
    ],
    'subscribe' => [
        'button' => 'Subscribe now',
    ],
    'manage' => [
        'title' => "You're on Pro",
        'body' => 'Update your payment method, download invoices, or change your plan in the billing portal.',
        'button' => 'Manage subscription',
        'auto_renews' => 'Renews automatically',
        'cancel_scheduled_title' => 'Cancellation scheduled',
        'cancel_scheduled_body' => 'Cloud Pro stays active until :date. After that, workspace access pauses.',
        'cancel_scheduled_legacy_body' => 'Cloud Pro stays active until :date. Then this workspace returns to its grandfathered Free plan.',
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
    'access' => [
        'paused_api' => 'This workspace is paused. Subscribe to Cloud Pro to restore access.',
        'paused_chat' => 'This workspace is paused because its Cloud trial or subscription ended. Subscribe to Cloud Pro to continue.',
    ],
    'deletion_notice' => 'Any active Pro subscription is canceled — Pro stays until the end of the paid period.',
];
