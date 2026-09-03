<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\DeleteEmailDraftAction;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Actions\ConfigureMailboxAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

/**
 * Unsent drafts saved by the composer. Opening one dispatches `composer:open`
 * with its id, which the floating composer picks up and loads.
 */
final class DraftsTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public function table(Table $table): Table
    {
        $composeEmail = $this->composeEmailAction();

        return $table
            ->query($this->buildQuery())
            ->defaultSort('updated_at', 'desc')
            ->headerActions([$composeEmail])
            ->emptyStateHeading(fn (): string => $this->hasMailbox()
                ? __('filament/pages/email-inbox.drafts.empty.heading')
                : __('filament/pages/email-accounts.not_connected.inbox.heading'))
            ->emptyStateDescription(fn (): string => $this->hasMailbox()
                ? __('filament/pages/email-inbox.drafts.empty.description')
                : __('filament/pages/email-accounts.not_connected.inbox.description'))
            ->emptyStateIcon(fn (): Heroicon => $this->hasMailbox()
                ? Heroicon::OutlinedPencilSquare
                : Heroicon::OutlinedEnvelope)
            ->emptyStateActions([
                $composeEmail,
                ConfigureMailboxAction::make(),
            ])
            ->recordAction('openDraft')
            ->columns([
                TextColumn::make('subject')
                    ->label(__('filament/pages/email-inbox.drafts.columns.subject'))
                    ->placeholder(__('filament/pages/email-inbox.drafts.columns.no_subject'))
                    ->limit(60)
                    ->searchable(),
                TextColumn::make('participants_to')
                    ->label(__('filament/pages/email-inbox.drafts.columns.recipients'))
                    ->placeholder(__('filament/pages/email-inbox.drafts.columns.no_recipients'))
                    ->state(fn (Email $record): string => $record->participants
                        ->where('role', EmailParticipantRole::TO)
                        ->pluck('email_address')
                        ->implode(', ')),
                TextColumn::make('updated_at')
                    ->label(__('filament/pages/email-inbox.drafts.columns.last_edited'))
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('openDraft')
                    ->label(__('filament/pages/email-inbox.drafts.actions.open'))
                    ->hiddenLabel()
                    ->tooltip(__('filament/pages/email-inbox.drafts.actions.open'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->action(fn (Email $record) => $this->dispatch('composer:open', draftId: (string) $record->getKey())),
                Action::make('deleteDraft')
                    ->label(__('filament/pages/email-inbox.drafts.actions.delete'))
                    ->hiddenLabel()
                    ->tooltip(__('filament/pages/email-inbox.drafts.actions.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Email $record): void {
                        resolve(DeleteEmailDraftAction::class)->execute($this->authUser(), (string) $record->getKey());

                        $this->dispatch('drafts:changed');

                        Notification::make()
                            ->success()
                            ->title(__('filament/pages/email-inbox.drafts.notifications.deleted'))
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('deleteDrafts')
                        ->label(__('filament/pages/email-inbox.drafts.actions.delete_selected'))
                        ->icon(Heroicon::OutlinedTrash)
                        ->color('danger')
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records): void {
                            $user = $this->authUser();

                            // Per record through the action, which re-checks ownership
                            // and clears each draft's stored attachments. A bulk delete
                            // must not become a shortcut around either.
                            $records->each(fn (Email $draft) => resolve(DeleteEmailDraftAction::class)
                                ->executeIfExists($user, (string) $draft->getKey()));

                            $this->dispatch('drafts:changed');

                            Notification::make()
                                ->success()
                                ->title(trans_choice(
                                    'filament/pages/email-inbox.drafts.notifications.bulk_deleted',
                                    $records->count(),
                                    ['count' => $records->count()],
                                ))
                                ->send();
                        }),
                ]),
            ]);
    }

    /**
     * Re-render when the composer saves or discards a draft, so the list matches
     * what the composer just did without a page reload.
     */
    #[On('drafts:changed')]
    public function refresh(): void {}

    public function render(): View
    {
        return view('email-integration::livewire.table');
    }

    private function composeEmailAction(): Action
    {
        return Action::make('composeEmail')
            ->label(__('filament/concerns/email-compose.actions.compose.label'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->tooltip(__('filament/concerns/email-compose.actions.compose.tooltip'))
            ->visible(fn (): bool => $this->hasMailbox())
            ->action(function (): void {
                $this->dispatch('composer:open');
            });
    }

    /**
     * Drafts are private to their author, so this is scoped to the signed-in
     * user within the current team, never the whole team.
     *
     * @return Builder<Email>
     */
    private function buildQuery(): Builder
    {
        return Email::query()
            ->with(['participants'])
            ->where('team_id', $this->currentTeam()?->getKey())
            ->where('user_id', auth()->id())
            ->where('status', EmailStatus::DRAFT);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function hasMailbox(): bool
    {
        return ConnectedAccount::hasConnectedFor($this->authUser(), $this->currentTeam());
    }

    private function currentTeam(): ?Team
    {
        $tenant = filament()->getTenant();

        if ($tenant instanceof Team) {
            return $tenant;
        }

        $team = $this->authUser()->currentTeam;

        return $team instanceof Team ? $team : null;
    }
}
