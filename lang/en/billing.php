<?php

declare(strict_types=1);

return [
    'title' => 'Billing',
    'subtitle' => 'Manage Cloud access, billing, and AI usage for this workspace.',
    'plan_section' => 'Current plan',
    'plans' => [
        'free' => 'Free',
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
    'current' => 'Current plan',
    'usage' => [
        'title' => 'AI credits this period',
        'of' => ':used of :allowance used',
        'count' => ':used / :allowance',
        'remaining' => ':count left',
        'resets' => 'Resets :date',
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
            'REST API and 30-tool MCP server',
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
        'monthly' => '$24 / month',
        'yearly' => '$228 / year — save 21%',
        'activating' => 'Payment received — activating Pro…',
    ],
    'subscribe' => [
        'button' => 'Subscribe now',
    ],
    'manage' => [
        'title' => "You're on Pro",
        'body' => 'Update your payment method, download invoices, or change your plan in the billing portal.',
        'button' => 'Manage subscription',
        'renews' => 'Renews :date',
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
