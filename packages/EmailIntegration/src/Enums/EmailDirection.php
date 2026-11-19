<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum EmailDirection: string implements HasLabel
{
    case INBOUND = 'inbound';
    case OUTBOUND = 'outbound';

    public function getLabel(?string $locale = null): string
    {
        return match ($this) {
            self::INBOUND => __('enums/email-direction.inbound', [], $locale),
            self::OUTBOUND => __('enums/email-direction.outbound', [], $locale),
        };
    }
}
