<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Illuminate\Support\Facades\Config;

enum MailboxImportPass: string
{
    case Full = 'full';
    case Recent = 'recent';

    public const int RECENT_DAYS = 90;

    public function daysBack(): ?int
    {
        return match ($this) {
            self::Recent => self::RECENT_DAYS,
            self::Full => self::historyCapDays(),
        };
    }

    private static function historyCapDays(): ?int
    {
        $days = Config::get('email-integration.sync.initial_days');

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }
}
