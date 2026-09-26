<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services\Factories;

use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

final readonly class MailServiceFactory implements MailServiceFactoryInterface
{
    public function __construct(
        private GmailServiceFactory $gmail,
        private MicrosoftGraphServiceFactory $microsoft,
    ) {}

    public function make(ConnectedAccount $account): MailServiceInterface
    {
        return match ($account->provider) {
            EmailProvider::GMAIL => $this->gmail->make($account),
            EmailProvider::AZURE => $this->microsoft->make($account),
        };
    }
}
