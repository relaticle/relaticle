<?php

declare(strict_types=1);

namespace App\Onboarding;

use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Enums\EmailProvider;

final readonly class MailboxAvailability
{
    public static function canConnect(): bool
    {
        return Feature::active(EmailIntegration::class)
            && array_any(EmailProvider::cases(), fn (EmailProvider $provider): bool => $provider->isConfigured());
    }
}
