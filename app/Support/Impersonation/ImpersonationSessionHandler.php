<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use Illuminate\Session\DatabaseSessionHandler;

final class ImpersonationSessionHandler extends DatabaseSessionHandler
{
    // A row stamped with the customer's id is a device in their browser sessions list.
    protected function userId(): mixed
    {
        if ($this->container->make(Impersonator::class)->active($this->container->make('request'))) {
            return null;
        }

        return parent::userId();
    }
}
