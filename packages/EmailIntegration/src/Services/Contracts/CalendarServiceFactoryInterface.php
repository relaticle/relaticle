<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Contracts;

use Relaticle\EmailIntegration\Models\ConnectedAccount;

interface CalendarServiceFactoryInterface
{
    public function make(ConnectedAccount $account): CalendarServiceInterface;
}
