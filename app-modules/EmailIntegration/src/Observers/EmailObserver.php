<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Observers;

use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailLinkingService;

final readonly class EmailObserver
{
    public function __construct(
        private EmailLinkingService $linkingService,
    ) {}

    public function created(Email $email): void
    {
        $this->linkingService->linkEmail($email);
    }
}
