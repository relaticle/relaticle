<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\MicrosoftGraphMailService;

final readonly class MicrosoftGraphServiceFactory
{
    public function __construct(private MicrosoftGraphClientFactory $clientFactory) {}

    public function make(ConnectedAccount $account): MailServiceInterface
    {
        return new MicrosoftGraphMailService($account, $this->clientFactory);
    }
}
