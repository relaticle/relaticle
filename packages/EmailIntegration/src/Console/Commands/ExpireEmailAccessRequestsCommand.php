<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\EmailIntegration\Actions\DecideEmailAccessRequest;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Notifications\EmailAccessRespondedNotification;

#[Description('Expire pending email access requests that passed their deadline')]
#[Signature('email:expire-access-requests')]
final class ExpireEmailAccessRequestsCommand extends Command
{
    public function handle(DecideEmailAccessRequest $decide): int
    {
        $expired = 0;

        $overdue = EmailAccessRequest::query()
            ->with(['requester', 'owner'])
            ->overdue()
            ->lazyById();

        foreach ($overdue as $request) {
            $this->info("Expiring access request `{$request->getKey()}`...");

            if (! $decide->execute($request, EmailAccessRequestStatus::EXPIRED, null)) {
                continue;
            }

            $request->requester->notify(new EmailAccessRespondedNotification($request));

            $expired++;
        }

        $this->comment("Expired {$expired} access request(s).");

        return self::SUCCESS;
    }
}
