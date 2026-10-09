<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Jobs;

use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Queue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\UniqueFor;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\ActiveAccountScope;

#[DeleteWhenMissingModels]
#[Queue('emails-import')]
#[Timeout(300)]
#[UniqueFor(3600)]
final class RelinkMailboxHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly ConnectedAccount $connectedAccount,
    ) {}

    public function handle(LinkEmailAction $linkEmail, LinkMeetingAction $linkMeeting): void
    {
        $account = $this->connectedAccount->fresh() ?? $this->connectedAccount;

        if ($account->status !== EmailAccountStatus::ACTIVE) {
            return;
        }

        $workspace = $account->workspace()->first();
        resolve(CurrentWorkspace::class)->within($account->workspace_id, function () use ($linkEmail, $linkMeeting, $account, $workspace): void {
            Email::query()
                ->withoutGlobalScope(ActiveAccountScope::class)
                ->where('connected_account_id', $account->getKey())
                ->lazyById(100)
                ->each(function (Email $email) use ($linkEmail, $account, $workspace): void {
                    $email->setRelation('connectedAccount', $account);

                    if ($workspace instanceof Workspace) {
                        $email->setRelation('workspace', $workspace);
                    }

                    $linkEmail->reapply($email);
                });

            Meeting::query()
                ->where('connected_account_id', $account->getKey())
                ->lazyById(100)
                ->each(function (Meeting $meeting) use ($linkMeeting, $account, $workspace): void {
                    $meeting->setRelation('connectedAccount', $account);

                    if ($workspace instanceof Workspace) {
                        $meeting->setRelation('workspace', $workspace);
                    }

                    $linkMeeting->execute($meeting);
                });
        });
    }

    public function uniqueId(): string
    {
        $account = $this->connectedAccount->fresh() ?? $this->connectedAccount;
        $batchId = $account->history_import_batch_id ?? 'none';

        return 'relink-history-'.$account->getKey().'-'.$batchId;
    }
}
