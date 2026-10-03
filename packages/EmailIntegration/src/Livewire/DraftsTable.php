<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
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
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\DeleteEmailDraftAction;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Actions\ConnectMailboxAction;
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

    private ?bool $hasMailbox = null;

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
                ConnectMailboxAction::make()
                    ->hidden(fn (): bool => $this->hasMailbox()),
            ])
            ->recordAction('openDraft')
            ->columns([
                TextColumn::make('subject')
                    ->label(__('filament/pages/email-inbox.drafts.columns.subject'))
                    ->placeholder(__('filament/pages/email-inbox.subject.none'))
                    ->limit(60)
                    ->description(fn (Email $record): ?string => $this->bodyPreview($record))
                    ->searchable(),
                TextColumn::make('updated_at')
                    ->label(__('filament/pages/email-inbox.drafts.columns.last_edited'))
                    ->since()
                    ->dateTimeTooltip()
                    ->color('gray')
                    ->alignEnd()
                    ->width('1%')
                    ->sortable(),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('openDraft')
                        ->label(__('filament/pages/email-inbox.drafts.actions.open'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->dispatch('composer:open', fn (Email $record): array => ['draftId' => (string) $record->getKey()])
                        // A row click mounts the action on the server, where the browser dispatch above never runs.
                        ->action(function (Email $record): void {
                            $this->dispatch('composer:open', draftId: (string) $record->getKey());
                        }),
                    Action::make('deleteDraft')
                        ->label(__('filament/pages/email-inbox.drafts.actions.delete'))
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
                ]),
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
            ->dispatch('composer:open');
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
            ->where('workspace_id', $this->currentWorkspace()?->getKey())
            ->where('user_id', auth()->id())
            ->where('status', EmailStatus::DRAFT);
    }

    private function bodyPreview(Email $record): ?string
    {
        $text = Str::squish(html_entity_decode((string) $record->snippet, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $text === '' ? null : Str::limit($text, 90);
    }

    private function authUser(): User
    {
        /** @var User */
        return auth()->user();
    }

    private function hasMailbox(): bool
    {
        return $this->hasMailbox ??= ConnectedAccount::hasConnectedFor($this->authUser(), $this->currentWorkspace());
    }

    private function currentWorkspace(): ?Workspace
    {
        $tenant = filament()->getTenant();

        if ($tenant instanceof Workspace) {
            return $tenant;
        }

        $team = $this->authUser()->currentWorkspace;

        return $team instanceof Workspace ? $team : null;
    }
}
