<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Relaticle\EmailIntegration\Actions\RecordMailboxHealth;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

#[Description('Store each connected mailbox health and send the notices it calls for.')]
#[Signature('email:check-mailbox-health')]
final class CheckMailboxHealthCommand extends Command
{
    private const int CHUNK = 500;

    /** @var array<string, bool> */
    private array $hostedAccessByWorkspace = [];

    public function handle(RecordMailboxHealth $recordMailboxHealth): int
    {
        ConnectedAccount::query()
            ->connected()
            ->chunkById(self::CHUNK, function (Collection $accounts) use ($recordMailboxHealth): void {
                $this->rememberHostedAccess($accounts);

                $accounts->each(function (ConnectedAccount $account) use ($recordMailboxHealth): void {
                    $health = $account->assessedHealth($this->hostedAccessByWorkspace[$account->workspace_id]);

                    $recordMailboxHealth->execute($account, $health, $account->healthReason($health));
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
}
