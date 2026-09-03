<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailVisibilityAction;
use Relaticle\EmailIntegration\Actions\UpdateTeamEmailVisibilityEntryAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailVisibilityEnforcement;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final class EmailVisibilityTable extends Component implements HasActions, HasSchemas
{
    use InteractsWithActions;
    use InteractsWithSchemas;

    public string $search = '';

    /**
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function visibilityRows(): array
    {
        $rows = resolve(EmailVisibilityService::class)->visibilityTableRows(
            $this->currentTeam(),
            $this->customEntries(),
        );

        $search = strtolower(trim($this->search));

        if ($search === '') {
            return $rows;
        }

        return array_values(array_filter(
            $rows,
            fn (array $row): bool => str_contains(strtolower((string) $row['address']), $search),
        ));
    }

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

        unset($this->visibilityRows);

        Notification::make()
            ->success()
            ->title(__('filament/pages/email-privacy-settings.visibility.notifications.updated'))
            ->send();
    }

    public function addVisibilityContactAction(): Action
    {
        return Action::make('addVisibilityContact')
            ->label(__('filament/pages/email-privacy-settings.visibility.add'))
            ->icon(Heroicon::OutlinedPlus)
            ->size(Size::Small)
            ->modalHeading(__('filament/pages/email-privacy-settings.visibility.add'))
            ->schema([
                TagsInput::make('visibility_emails')
                    ->label(__('filament/pages/email-privacy-settings.visibility.emails_label'))
                    ->placeholder(__('filament/pages/email-privacy-settings.visibility.emails_placeholder'))
                    ->afterLabel(__('filament/pages/email-privacy-settings.visibility.emails_after_label'))
                    ->nestedRecursiveRules(['email', 'max:255']),
                TagsInput::make('visibility_domains')
                    ->label(__('filament/pages/email-privacy-settings.visibility.domains_label'))
                    ->placeholder(__('filament/pages/email-privacy-settings.visibility.domains_placeholder'))
                    ->afterLabel(__('filament/pages/email-privacy-settings.visibility.domains_after_label'))
                    ->nestedRecursiveRules(['regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i', 'max:255']),
                Select::make('enforcement_level')
                    ->label(__('filament/pages/email-privacy-settings.visibility.table.enforcement'))
                    ->options(EmailVisibilityEnforcement::class)
                    ->default(EmailVisibilityEnforcement::Protected->value)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $user = $this->authUser();
                $enforcement = $this->resolveEnforcement($data['enforcement_level']);

                resolve(UpdateTeamEmailVisibilityAction::class)->execute(
                    $this->currentTeam(),
                    $user,
                    $this->mergedVisibilityEntries(
                        $data['visibility_emails'] ?? [],
                        $data['visibility_domains'] ?? [],
                        $enforcement,
                    ),
                );

                unset($this->visibilityRows);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-privacy-settings.visibility.notifications.added'))
                    ->send();
            });
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

                unset($this->visibilityRows);

                Notification::make()
                    ->success()
                    ->title(__('filament/pages/email-privacy-settings.visibility.notifications.deleted'))
                    ->send();
            });
    }

    public function render(): View
    {
        return view('email-integration::livewire.email-visibility-table');
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

    /**
     * @param  array<int, string>  $newEmails
     * @param  array<int, string>  $newDomains
     * @return list<array{type: string, value: string, enforcement_level: EmailVisibilityEnforcement}>
     */
    private function mergedVisibilityEntries(array $newEmails, array $newDomains, EmailVisibilityEnforcement $enforcement): array
    {
        $entries = $this->customEntries()
            ->map(fn (TeamEmailBlocklist $entry): array => [
                'type' => $entry->type->value,
                'value' => $entry->value,
                'enforcement_level' => $entry->enforcement_level ?? EmailVisibilityEnforcement::Blocked,
            ])
            ->all();

        foreach ($newEmails as $email) {
            if (blank($email)) {
                continue;
            }

            $entries[] = [
                'type' => EmailBlocklistType::EMAIL->value,
                'value' => strtolower(trim($email)),
                'enforcement_level' => $enforcement,
            ];
        }

        foreach ($newDomains as $domain) {
            if (blank($domain)) {
                continue;
            }

            $entries[] = [
                'type' => EmailBlocklistType::DOMAIN->value,
                'value' => strtolower(trim($domain)),
                'enforcement_level' => $enforcement,
            ];
        }

        return collect($entries)
            ->unique(fn (array $entry): string => $entry['type'].'|'.$entry['value'])
            ->values()
            ->all();
    }

    private function resolveEnforcement(mixed $value): EmailVisibilityEnforcement
    {
        if ($value instanceof EmailVisibilityEnforcement) {
            return $value;
        }

        return EmailVisibilityEnforcement::from((string) $value);
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
