<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Auth;

use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;

/**
 * Filament keeps its disable action even where the panel marks the factor required,
 * and disabling it there only bounces the administrator straight back to enrolment.
 */
final class RequiredAppAuthentication extends AppAuthentication
{
    /**
     * @return array<Action>
     */
    public function getActions(): array
    {
        return array_values(array_filter(
            parent::getActions(),
            fn (Action $action): bool => $action->getName() !== 'disableAppAuthentication',
        ));
    }
}
