<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountSettingsPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

/**
 * In-page mailbox history import section for Home and Email Accounts.
 */
final class MailboxImportStatus extends Component
{
    #[Locked]
    public string $placement = 'home';

    /** @var list<string> */
    public array $dismissedAccountIds = [];

    /** @var list<string> */
    public array $seenImportingIds = [];

    /** @var Collection<int, ConnectedAccount>|null */
    private ?Collection $ownedAccountsCache = null;

    public function mount(): void
    {
        $this->refreshStatus();
    }

    public function refreshStatus(): void
    {
        $this->ownedAccountsCache = null;

        foreach ($this->importingOwnedAccounts() as $account) {
            $id = (string) $account->getKey();

            if (! in_array($id, $this->seenImportingIds, true)) {
                $this->seenImportingIds[] = $id;
            }
        }
    }

    public function dismiss(string $accountId): void
    {
        if (! in_array($accountId, $this->dismissedAccountIds, true)) {
            $this->dismissedAccountIds[] = $accountId;
        }
    }

    public function shouldRender(): bool
    {
        return $this->visibleMailboxes() !== [];
    }

    public function shouldPoll(): bool
    {
        return array_any($this->visibleMailboxes(), fn (array $row): bool => $row['importing']);
    }

    /**
     * @return list<array{id: string, email: string, imported: int, meetingsImported: int, hasCalendar: bool, percent: int, importing: bool, settings_url: string}>
     */
    public function visibleMailboxes(): array
    {
        $rows = [];

        foreach ($this->ownedAccounts() as $account) {
            $id = (string) $account->getKey();

            if (in_array($id, $this->dismissedAccountIds, true)) {
                continue;
            }

            if (! $account->isActive()) {
                continue;
            }

            if (! $account->isImportingHistory() && ! in_array($id, $this->seenImportingIds, true)) {
                continue;
            }

            $rows[] = [
                'id' => $id,
                'email' => $account->email_address,
                'imported' => $account->initial_sync_imported,
                'meetingsImported' => $account->initial_calendar_sync_imported,
                'hasCalendar' => $account->hasCalendar(),
                'percent' => $account->initialSyncProgressPercent(),
                'importing' => $account->isImportingHistory(),
                'settings_url' => EmailAccountSettingsPage::getUrl(['account' => $id]),
            ];
        }

        return $rows;
    }

    public function render(): View
    {
        $mailboxes = $this->visibleMailboxes();

        return view('email-integration::livewire.mailbox-import-status', [
            'mailboxes' => $mailboxes,
            'anyImporting' => array_any($mailboxes, fn (array $row): bool => $row['importing']),
            'shouldPoll' => $this->shouldPoll(),
        ]);
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function importingOwnedAccounts(): Collection
    {
        return $this->ownedAccounts()
            ->filter(fn (ConnectedAccount $account): bool => $account->isImportingHistory());
    }

    /**
     * @return Collection<int, ConnectedAccount>
     */
    private function ownedAccounts(): Collection
    {
        if ($this->ownedAccountsCache instanceof Collection) {
            return $this->ownedAccountsCache;
        }

        $user = auth()->user();
        $team = filament()->getTenant();

        if (! $user instanceof User || ! $team instanceof Team) {
            return $this->ownedAccountsCache = new Collection;
        }

        return $this->ownedAccountsCache = ConnectedAccount::query()->ownedBy($user, $team)->get();
    }
}
