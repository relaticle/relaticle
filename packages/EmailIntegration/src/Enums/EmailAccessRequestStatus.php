<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum EmailAccessRequestStatus: string implements HasColor, HasLabel
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case DENIED = 'denied';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => __('filament/pages/email-access-requests.statuses.pending'),
            self::APPROVED => __('filament/pages/email-access-requests.statuses.approved'),
            self::DENIED => __('filament/pages/email-access-requests.statuses.denied'),
            self::CANCELLED => __('filament/pages/email-access-requests.statuses.cancelled'),
            self::EXPIRED => __('filament/pages/email-access-requests.statuses.expired'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::APPROVED => 'success',
            self::DENIED => 'danger',
            self::CANCELLED, self::EXPIRED => 'gray',
        };
    }
}
