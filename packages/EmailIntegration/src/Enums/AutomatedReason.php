<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Enums;

use Filament\Support\Contracts\HasLabel;

enum AutomatedReason: string implements HasLabel
{
    case List = 'list';
    case AutoSubmitted = 'auto_submitted';
    case Bulk = 'bulk';
    case RoleSender = 'role_sender';
    case ProviderCategory = 'provider_category';

    public function getLabel(): string
    {
        return match ($this) {
            self::List => __('filament/pages/email-inbox.automated_reasons.list'),
            self::AutoSubmitted => __('filament/pages/email-inbox.automated_reasons.auto_submitted'),
            self::Bulk => __('filament/pages/email-inbox.automated_reasons.bulk'),
            self::RoleSender => __('filament/pages/email-inbox.automated_reasons.role_sender'),
            self::ProviderCategory => __('filament/pages/email-inbox.automated_reasons.provider_category'),
        };
    }
}
