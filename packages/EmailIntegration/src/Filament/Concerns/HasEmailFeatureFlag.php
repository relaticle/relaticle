<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Concerns;

use App\Features\EmailIntegration;
use Laravel\Pennant\Feature;

trait HasEmailFeatureFlag
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return Feature::active(EmailIntegration::class) && parent::canAccess();
    }
}
