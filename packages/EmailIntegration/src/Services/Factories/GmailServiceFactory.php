<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Google\Service\Gmail;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\GmailService;

final readonly class GmailServiceFactory
{
    public function __construct(private GoogleClientFactory $clientFactory) {}

    public function make(ConnectedAccount $account): MailServiceInterface
    {
        $client = $this->clientFactory->make($account);

        return new GmailService($account, new Gmail($client));
    }
}
