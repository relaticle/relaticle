<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailVisibilityEntryAction;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final class EmailVisibilityTable extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public string $search = '';

    /**
     * Re-render when contacts are added from the page header action.
     */
    #[On('visibility-entries-updated')]
    public function refreshVisibilityEntries(): void {}

    public function updateEnforcement(string $entryId, string $enforcement): void
    {
        $entry = TeamEmailBlocklist::query()
            ->where('team_id', $this->currentTeam()->getKey())
            ->whereKey($entryId)
            ->firstOrFail();

        resolve(UpdateTeamEmailVisibilityEntryAction::class)->execute(
            $this->currentTeam(),
            $this->authUser(),
            $entry,
            EmailVisibilityEnforcement::from($enforcement),
        );

        Notification::make()
            ->success()
            ->title(__('filament/pages/email-privacy-settings.visibility.notifications.updated'))
            ->send();
    }

    public function deleteVisibilityEntryAction(): Action
    {
        return Action::make('deleteVisibilityEntry')
            ->label(__('filament/pages/email-signatures.actions.delete'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->size(Size::Small)
            ->iconButton()
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                TeamEmailBlocklist::query()
                    ->where('team_id', $this->currentTeam()->getKey())
                    ->whereKey((string) $arguments['entry_id'])
                    ->firstOrFail()
                    ->delete();

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-privacy-settings.visibility.notifications.deleted'))
                    ->send();
            });
    }

    public function render(): View
    {
        $search = trim($this->search);

        return view('email-integration::livewire.email-visibility-table', [
            'rows' => $this->visibilityRecords($search !== '' ? $search : null),
        ]);
    }

    public function hasCustomVisibilityEntries(): bool
    {
        return $this->customEntries()->isNotEmpty();
    }

    /**
     * @return SupportCollection<string, array{key: string, address: string, enforcement: string, enforcement_value: string, source: string, is_system: bool, entry_id?: string, updated_at?: string}>
     */
    private function visibilityRecords(?string $search): SupportCollection
    {
        $rows = resolve(EmailVisibilityService::class)->visibilityTableRows(
            $this->currentTeam(),
            $this->customEntries(),
        );

        /** @var SupportCollection<string, array{key: string, address: string, enforcement: string, enforcement_value: string, source: string, is_system: bool, entry_id?: string, updated_at?: string}> $records */
        $records = collect($rows)->mapWithKeys(
            fn (array $row): array => [(string) $row['key'] => $row],
        );

        if (blank($search)) {
            return $records;
        }

        $needle = Str::lower(trim($search));

        return $records->filter(
            fn (array $row): bool => str_contains(Str::lower((string) $row['address']), $needle),
        );
    }

    /**
     * @return Collection<int, TeamEmailBlocklist>
     */
    private function customEntries(): Collection
    {
        return TeamEmailBlocklist::query()
            ->where('team_id', $this->currentTeam()->getKey())
            ->with('creator')
            ->latest()
            ->get();
    }

    private function authUser(): User
    {
        /** @var User $user */
        $user = auth()->user();

        return $user;
    }

    private function currentTeam(): Team
    {
        $tenant = filament()->getTenant();

        if ($tenant instanceof Team) {
            return $tenant;
        }

        return $this->authUser()->currentTeam;
    }
}
