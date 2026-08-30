<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;

#[DeleteWhenMissingModels]
final class RelinkMailboxHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {
        $this->onQueue('emails-sync');
    }

    public function handle(LinkEmailAction $linkEmail, LinkMeetingAction $linkMeeting): void
    {
        $account = $this->connectedAccount;

        if ($account->status !== EmailAccountStatus::ACTIVE) {
            return;
        }

        Email::query()
            ->where('connected_account_id', $account->getKey())
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (Email $email) use ($linkEmail): void {
                $linkEmail->execute($email);
            });

        Meeting::query()
            ->where('connected_account_id', $account->getKey())
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (Meeting $meeting) use ($linkMeeting): void {
                $linkMeeting->execute($meeting);
            });
    }

    public function uniqueId(): string
    {
        return 'relink-history-'.$this->connectedAccount->getKey();
    }
}
