<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\CalendarServiceInterface;
use Relaticle\EmailIntegration\Services\GoogleCalendarService;
use Relaticle\EmailIntegration\Services\MicrosoftCalendarService;

final readonly class CalendarServiceFactory implements CalendarServiceFactoryInterface
{
    public function __construct(private MicrosoftGraphClientFactory $microsoftFactory) {}

    public function make(ConnectedAccount $account): CalendarServiceInterface
    {
        return match ($account->provider) {
            EmailProvider::GMAIL => GoogleCalendarService::forAccount($account),
            EmailProvider::AZURE => new MicrosoftCalendarService($account, $this->microsoftFactory),
        };
    }
}
