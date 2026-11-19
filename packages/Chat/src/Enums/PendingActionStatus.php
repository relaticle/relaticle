<?php

declare(strict_types=1);

namespace Relaticle\Chat\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PendingActionStatus: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Superseded = 'superseded';

    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
            self::Expired, self::Superseded => 'gray',
        };
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => __('enums/pending-action-status.pending'),
            self::Approved => __('enums/pending-action-status.approved'),
            self::Rejected => __('enums/pending-action-status.rejected'),
            self::Expired => __('enums/pending-action-status.expired'),
            self::Superseded => __('enums/pending-action-status.superseded'),
        };
    }

    public function promptWord(): string
    {
        return match ($this) {
            self::Pending => 'PENDING',
            self::Approved => 'APPROVED (written)',
            self::Rejected => 'REJECTED (nothing was written)',
            self::Expired => 'EXPIRED (nothing was written)',
            self::Superseded => 'SUPERSEDED (nothing was written)',
        };
    }
}
