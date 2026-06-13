<?php

declare(strict_types=1);

return [
    'title' => 'Billing',
    'subtitle' => 'Manage your workspace plan and AI usage.',
    'plan_section' => 'Current plan',
    'plans' => [
        'free' => 'Free',
        'pro' => 'Pro',
        'enterprise' => 'Enterprise',
    ],
    'status' => [
        'free' => 'Free plan',
        'trialing' => 'Trial',
        'active' => 'Active',
        'past_due' => 'Past due',
        'canceling' => 'Canceling',
        'enterprise' => 'Managed',
    ],
    'current' => 'Current plan',
    'recommended' => 'Recommended',
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
    ],
    'free_plan' => [
        'tagline' => 'Everything you need to run your CRM',
        'after_trial' => 'Your plan after the trial',
        'features' => [
            'Unlimited users and records',
            '300 AI credits / month',
            'Fast AI models',
            'MCP server with 30 tools',
            'REST API with full CRUD',
        ],
    ],
    'pro_plan' => [
        'tagline' => 'For teams that put AI to work',
        'per_workspace' => 'Per workspace. Never per seat.',
        'features' => [
            'Everything in Free',
            '2,000 AI credits / month',
            'All AI models, including premium',
            '30 requests / minute',
            'Email support',
        ],
        'monthly' => 'Monthly',
        'yearly' => 'Yearly',
        'yearly_save' => '2 months free',
        'billed_monthly' => 'Billed monthly · cancel anytime',
        'billed_yearly' => 'Billed annually · cancel anytime',
    ],
    'upgrade' => [
        'button' => 'Upgrade to Pro',
        'now' => 'Upgrade now instead',
        'monthly' => '$29 / month',
        'yearly' => '$290 / year — 2 months free',
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
