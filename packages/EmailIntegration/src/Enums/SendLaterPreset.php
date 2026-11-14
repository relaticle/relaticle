<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

enum SendLaterPreset: string
{
    case Tomorrow = 'tomorrow';
    case Monday = 'monday';

    public function resolve(User $sender): CarbonImmutable
    {
        $local = now($sender->effectiveTimezone());

        return (match ($this) {
            self::Tomorrow => $local->addDay(),
            self::Monday => $local->next(CarbonInterface::MONDAY),
        })->setTime(8, 0)->utc();
    }

    public function label(): string
    {
        return __('filament/emails/composer.send_later.'.$this->value);
    }
}
