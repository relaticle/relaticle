<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Livewire;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DateTimePicker;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RescheduleQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RetryFailedEmailAction;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\OutboxTab;
use Relaticle\EmailIntegration\Models\Email;
use RuntimeException;

/**
 * The Outbox tab of the email page.
 */
final class OutboxTable extends Component implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions;
    use InteractsWithSchemas;
    use InteractsWithTable;

    public ?EmailStatus $lockedStatus = null;

    public bool $includeFailedFilter = true;

    // Nullable so Livewire reads an unknown ?status= with tryFrom() instead of throwing.
    #[Url(as: 'status')]
    public ?OutboxTab $statusTab = OutboxTab::QUEUED;

    public function setStatusTab(string $tab): void
    {
        $this->statusTab = OutboxTab::tryFrom($tab) ?? OutboxTab::QUEUED;
        $this->resetTable();
    }

    /**
     * @return list<OutboxTab>
     */
    public function segments(): array
    {
        if ($this->lockedStatus instanceof EmailStatus) {
            return [];
        }

        return array_values(array_filter(
            OutboxTab::cases(),
            fn (OutboxTab $tab): bool => $this->includeFailedFilter || $tab !== OutboxTab::FAILED,
        ));
    }

    /**
     * @return array<string, int>
     */
    #[Computed]
    public function segmentCounts(): array
    {
        return collect($this->segments())
            ->mapWithKeys(fn (OutboxTab $tab): array => [$tab->value => $tab->constrain($this->ownOutboundQuery())->count()])
            ->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->buildQuery())
            ->defaultSort($this->sortSegment(...))
            ->emptyStateHeading(fn (): string => $this->lockedStatus === EmailStatus::FAILED
                ? __('filament/pages/email-inbox.failed.empty.heading')
                : __('filament/pages/email-inbox.outbox.empty.heading'))
            ->emptyStateDescription(fn (): string => $this->lockedStatus === EmailStatus::FAILED
                ? __('filament/pages/email-inbox.failed.empty.description')
                : __('filament/pages/email-inbox.outbox.empty.description'))
            ->emptyStateIcon(fn (): Heroicon => $this->lockedStatus === EmailStatus::FAILED
                ? Heroicon::OutlinedExclamationCircle
                : Heroicon::OutlinedClock)
            ->columns([
                TextColumn::make('subject')->label(__('filament/pages/email-outbox.columns.subject'))->limit(50)->searchable(),
                TextColumn::make('participants_to')
                    ->label(__('filament/pages/email-outbox.columns.recipients'))
                    ->state(fn (Email $record): string => $record->participants
                        ->where('role', 'to')->pluck('email_address')->implode(', ')),
                TextColumn::make('status')->label(__('filament/pages/email-outbox.columns.status'))->badge(),
                TextColumn::make('scheduled_for')->dateTime()->label(__('filament/pages/email-outbox.columns.scheduled_for')),
                TextColumn::make('priority')->label(__('filament/pages/email-outbox.columns.type'))->badge(),
                TextColumn::make('last_error')
                    ->label(__('filament/pages/email-outbox.columns.last_error'))
                    ->toggleable(isToggledHiddenByDefault: $this->lockedStatus !== EmailStatus::FAILED)
                    ->wrap(),
            ])
            ->recordActions($this->rowActions())
            ->toolbarActions([
                BulkAction::make('bulkCancel')
                    ->label(__('filament/pages/email-outbox.actions.bulk_cancel'))
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $cancelled = 0;
                        $skipped = 0;
                        $action = resolve(CancelQueuedEmailAction::class);
                        foreach ($records as $record) {
                            if (! $record instanceof Email) {
                                continue;
                            }

                            try {
                                // The pre-check on $record->status uses stale, table-loaded
                                // data; the action re-locks the row and throws if it is no
                                // longer QUEUED (e.g. it transitioned to SENDING since render).
                                // Skip those rather than aborting the whole batch.
                                $action->execute($record);
                                $cancelled++;
                            } catch (RuntimeException) {
                                $skipped++;
                            }
                        }

                        $title = $skipped > 0
                            ? __('filament/pages/email-outbox.notifications.bulk_cancelled_with_skipped', ['cancelled' => $cancelled, 'skipped' => $skipped])
                            : trans_choice('filament/pages/email-outbox.notifications.bulk_cancelled', $cancelled);

                        $this->dispatch('outbox:changed');
                        Notification::make()->title($title)->success()->send();
                    }),
            ]);
    }

    /**
     * Re-render when a send is queued or cancelled, so the list matches the
     * outbox badge without a page reload.
     */
    #[On('outbox:changed')]
    public function refresh(): void {}

    public function render(): View
    {
        return view('email-integration::livewire.outbox-table', ['activeSegment' => $this->activeSegment()]);
    }

    /**
     * @return list<Action>
     */
    private function rowActions(): array
    {
        return [
            Action::make('cancel')
                ->label(__('filament/pages/email-outbox.actions.cancel'))
                ->icon(Heroicon::OutlinedXMark)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Email $record): bool => $record->status === EmailStatus::QUEUED)
                ->action(function (Email $record): void {
                    resolve(CancelQueuedEmailAction::class)->execute($record);
                    $this->dispatch('outbox:changed');
                    Notification::make()->title(__('filament/pages/email-outbox.notifications.cancelled'))->success()->send();
                }),
            Action::make('reschedule')
                ->label(__('filament/pages/email-outbox.actions.reschedule'))
                ->icon(Heroicon::OutlinedClock)
                ->visible(fn (Email $record): bool => $record->status === EmailStatus::QUEUED)
                ->schema([
                    DateTimePicker::make('scheduled_for')
                        ->label(__('filament/pages/email-outbox.actions.reschedule_field'))
                        ->native(false)
                        ->seconds(false)
                        ->minDate(now())
                        ->required(),
                ])
                ->fillForm(fn (Email $record): array => ['scheduled_for' => $record->scheduled_for])
                ->action(function (Email $record, array $data): void {
                    resolve(RescheduleQueuedEmailAction::class)->execute($record, Date::parse((string) $data['scheduled_for']));
                    $this->dispatch('outbox:changed');
                    Notification::make()->title(__('filament/pages/email-outbox.notifications.rescheduled'))->success()->send();
                }),
            Action::make('retry')
                ->label(__('filament/pages/email-outbox.actions.retry'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('warning')
                ->visible(fn (Email $record): bool => $record->status === EmailStatus::FAILED)
                ->action(function (Email $record): void {
                    resolve(RetryFailedEmailAction::class)->execute($record);
                    $this->dispatch('outbox:changed');
                    Notification::make()->title(__('filament/pages/email-outbox.notifications.retry_queued'))->success()->send();
                }),
        ];
    }

    /**
     * @return Builder<Email>
     */
    private function buildQuery(): Builder
    {
        $query = $this->ownOutboundQuery()->with(['participants']);

        return $this->lockedStatus instanceof EmailStatus
            ? $query->where('status', $this->lockedStatus)
            : $this->activeSegment()->constrain($query);
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function sortSegment(Builder $query): Builder
    {
        return match (true) {
            $this->lockedStatus instanceof EmailStatus => $query,
            $this->activeSegment() === OutboxTab::SENT => $query->latest('sent_at'),
            default => $query->orderByRaw('scheduled_for asc nulls first')->oldest('created_at'),
        };
    }

    private function activeSegment(): OutboxTab
    {
        return in_array($this->statusTab, $this->segments(), true) ? $this->statusTab : OutboxTab::QUEUED;
    }

    /**
     * @return Builder<Email>
     */
    private function ownOutboundQuery(): Builder
    {
        return Email::query()
            ->where('workspace_id', filament()->getTenant()?->getKey())
            ->where('user_id', auth()->id())
            ->where('direction', EmailDirection::OUTBOUND);
    }
}
