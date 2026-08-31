<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Relaticle\CustomFields\Services\TenantContextService;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;

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
        $account = $this->connectedAccount->fresh() ?? $this->connectedAccount;

        if ($account->status !== EmailAccountStatus::ACTIVE) {
            return;
        }

        $team = $account->team()->first();
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($account->team_id);

        try {
            Email::query()
                ->withoutGlobalScope(ActiveAccountScope::class)
                ->where('connected_account_id', $account->getKey())
                ->lazyById(100)
                ->each(function (Email $email) use ($linkEmail, $account, $team): void {
                    $email->setRelation('connectedAccount', $account);

                    if ($team instanceof Team) {
                        $email->setRelation('team', $team);
                    }

                    $linkEmail->reapply($email);
                });

            Meeting::query()
                ->where('connected_account_id', $account->getKey())
                ->lazyById(100)
                ->each(function (Meeting $meeting) use ($linkMeeting, $account, $team): void {
                    $meeting->setRelation('connectedAccount', $account);

                    if ($team instanceof Team) {
                        $meeting->setRelation('team', $team);
                    }

                    $linkMeeting->execute($meeting);
                });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }

    public function uniqueId(): string
    {
        return 'relink-history-'.$this->connectedAccount->getKey();
    }
}
