<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

enum SyncErrorReason: string
{
    case GoogleMailDisabled = 'google.mail_disabled';

    public function getLabel(): string
    {
        return match ($this) {
            self::GoogleMailDisabled => __('filament/pages/email-accounts.sync_error.reasons.google_mail_disabled'),
        };
    }
}
