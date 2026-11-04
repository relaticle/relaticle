<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Relaticle\EmailIntegration\Actions\RecordMailboxHealth;
use Relaticle\EmailIntegration\Enums\MailboxHealth;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Notifications\MailboxHealthNotification;

#[Description('Store each connected mailbox health and send the notices it calls for.')]
#[Signature('email:check-mailbox-health')]
final class CheckMailboxHealthCommand extends Command
{
    private const int CHUNK = 500;

    private const int ADMIN_NOTICE_AFTER_HOURS = 72;

    /** @var array<string, bool> */
    private array $hostedAccessByWorkspace = [];

    public function handle(RecordMailboxHealth $recordMailboxHealth): int
    {
        $this->hostedAccessByWorkspace = [];

        ConnectedAccount::query()
            ->connected()
            ->with(['user', 'workspace'])
            ->chunkById(self::CHUNK, function (Collection $accounts) use ($recordMailboxHealth): void {
                $this->rememberHostedAccess($accounts);

                $accounts->each(function (ConnectedAccount $account) use ($recordMailboxHealth): void {
                    $health = $account->assessedHealth($this->hostedAccessByWorkspace[$account->workspace_id]);

                    $recordMailboxHealth->execute($account, $health, $account->healthReason($health));
                    $this->sendDueNotices($account);
                });
            });

        return self::SUCCESS;
    }

    /** @param  Collection<int, ConnectedAccount>  $accounts */
    private function rememberHostedAccess(Collection $accounts): void
    {
        $unknown = $accounts->pluck('workspace_id')->unique()->reject(fn (string $id): bool => isset($this->hostedAccessByWorkspace[$id]));

        if ($unknown->isEmpty()) {
            return;
        }

        $granted = Workspace::query()->whereKey($unknown->all())->withHostedAccess()->pluck('id')->flip();

        $unknown->each(function (string $id) use ($granted): void {
            $this->hostedAccessByWorkspace[$id] = $granted->has($id);
        });
    }

    private function sendDueNotices(ConnectedAccount $account): void
    {
        $changedAt = $account->health_changed_at;

        if ($account->health === MailboxHealth::Healthy || $changedAt === null || ! $account->user instanceof User) {
            return;
        }

        $notifiedAt = $account->health_notified_at;
        $ownerDue = $notifiedAt === null || $notifiedAt->lt($changedAt);
        $adminsDue = $account->health->notifiesAdminsAtOnce()
            ? $ownerDue
            : $changedAt->lte(now()->subHours(self::ADMIN_NOTICE_AFTER_HOURS))
                && ($notifiedAt === null || $notifiedAt->lt($changedAt->addHours(self::ADMIN_NOTICE_AFTER_HOURS)));

        if ($ownerDue) {
            $account->user->notify(new MailboxHealthNotification($account, forAdmins: false));
        }

        if ($adminsDue) {
            Notification::send($this->adminsOf($account), new MailboxHealthNotification($account, forAdmins: true));
        }

        if ($ownerDue || $adminsDue) {
            $account->forceFill(['health_notified_at' => now()])->save();
        }
    }

    /** @return Collection<int, User> */
    private function adminsOf(ConnectedAccount $account): Collection
    {
        return $account->workspace->loadMissing(['users', 'owner'])->allUsers()
            ->reject(fn (User $user): bool => $user->is($account->user))
            ->filter(fn (User $user): bool => $user->hasWorkspaceCapability($account->workspace_id, WorkspaceCapability::EmailManage))
            ->values()
            ->toBase();
    }
}
