<?php

declare(strict_types=1);

return [
    'title' => 'Forwarding address',
    'subheading' => 'Update your forwarding permissions and settings.',
    'learn_more' => 'Learn more about forwarding email',
    'tabs' => [
        'general' => 'General',
        'blocklist' => 'Mailbox-only blocklist',
    ],
    'visibility' => [
        'label' => 'Email visibility',
        'hint' => 'Choose how much of your forwarded email your workspace can see by default. You will always see everything in your own mailbox.',
    ],
    'full_access' => [
        'label' => 'Individuals with full access',
        'hint' => 'They see all your forwarded email, including attachments, even if your workspace default is more restrictive.',
        'add' => 'Add person',
        'only_when_restricted' => 'Only applicable if metadata only or subject line and metadata is selected.',
        'empty' => 'No teammates added yet.',
        'notifications' => [
            'added' => 'Teammate added.',
            'removed' => 'Teammate removed.',
        ],
    ],
    'blocklist' => [
        'label' => 'Blocklist',
        'hint' => 'Emails from blocklisted domains and addresses will not appear in Relaticle.',
        'empty_heading' => 'No emails or domains yet',
        'empty_description' => 'Emails from blocklisted domains and addresses will not appear in Relaticle.',
        'add' => 'Add to blocklist',
        'emails_label' => 'Email addresses',
        'emails_placeholder' => 'Add an email address',
        'emails_after_label' => 'Separate multiple addresses with commas.',
        'domains_label' => 'Domains',
        'domains_placeholder' => 'Add a domain',
        'domains_after_label' => 'Separate multiple domains with commas.',
        'notifications' => [
            'added' => 'Blocklist updated.',
            'deleted' => 'Entry removed.',
        ],
    ],
    'notifications' => [
        'saved' => 'Forwarding settings saved.',
    ],
];
