<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\GoogleCalendarService;

final class GoogleCalendarServiceFactory implements CalendarServiceFactoryInterface
{
    public function make(ConnectedAccount $account): CalendarServiceInterface
    {
        return GoogleCalendarService::forAccount($account);
    }
}
