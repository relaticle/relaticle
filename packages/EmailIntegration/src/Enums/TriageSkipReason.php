<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

enum TriageSkipReason: string
{
    case NotAvailable = 'not_available';
    case NotInYourMailbox = 'not_in_your_mailbox';

    public function label(): string
    {
        return match ($this) {
            self::NotAvailable => __('filament/pages/email-inbox.triage.skipped.not_available'),
            self::NotInYourMailbox => __('filament/pages/email-inbox.triage.skipped.not_in_your_mailbox'),
        };
    }
}
