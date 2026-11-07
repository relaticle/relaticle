<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailProvider;

final readonly class MailboxStep
{
    /**
     * @return list<string>
     */
    public static function configuredProviders(): array
    {
        return array_values(array_map(
            fn (EmailProvider $provider): string => $provider->value,
            array_filter(EmailProvider::cases(), fn (EmailProvider $provider): bool => $provider->isConfigured()),
        ));
    }

    public static function canRun(): bool
    {
        return Feature::active(EmailIntegration::class) && self::configuredProviders() !== [];
    }
}
