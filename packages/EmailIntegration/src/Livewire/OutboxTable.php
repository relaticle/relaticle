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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
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

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->filters($this->lockedStatus instanceof EmailStatus ? [] : [$this->statusFilter()])
            ->when(
                $this->lockedStatus === EmailStatus::FAILED,
                fn (Table $table): Table => $table
                    ->emptyStateHeading(__('filament/pages/email-inbox.failed.empty.heading'))
                    ->emptyStateDescription(__('filament/pages/email-inbox.failed.empty.description'))
                    ->emptyStateIcon(Heroicon::ExclamationCircle),
            )
            ->columns([
                TextColumn::make('subject')->limit(50)->searchable(),
                TextColumn::make('participants_to')
                    ->label(__('filament/pages/email-outbox.columns.recipients'))
                    ->state(fn (Email $record): string => $record->participants
                        ->where('role', 'to')->pluck('email_address')->implode(', ')),
                TextColumn::make('status')->badge(),
                TextColumn::make('scheduled_for')->dateTime()->label(__('filament/pages/email-outbox.columns.scheduled_for')),
                TextColumn::make('priority')->badge(),
                TextColumn::make('last_error')
                    ->toggleable(isToggledHiddenByDefault: $this->lockedStatus !== EmailStatus::FAILED)
                    ->wrap(),
            ])
            ->recordActions([
                Action::make('cancel')
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
                    ->icon(Heroicon::OutlinedClock)
                    ->visible(fn (Email $record): bool => $record->status === EmailStatus::QUEUED)
                    ->schema([
                        DateTimePicker::make('scheduled_for')
                            ->label(__('filament/pages/email-outbox.actions.reschedule_field'))
                            ->seconds(false)
                            ->minDate(now())
                            ->required(),
                    ])
                    ->fillForm(fn (Email $record): array => ['scheduled_for' => $record->scheduled_for])
                    ->action(function (Email $record, array $data): void {
                        resolve(RescheduleQueuedEmailAction::class)->execute($record, Date::parse((string) $data['scheduled_for']));
                        Notification::make()->title(__('filament/pages/email-outbox.notifications.rescheduled'))->success()->send();
                    }),
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (Email $record): bool => $record->status === EmailStatus::FAILED)
                    ->action(function (Email $record): void {
                        resolve(RetryFailedEmailAction::class)->execute($record);
                        $this->dispatch('outbox:changed');
                        Notification::make()->title(__('filament/pages/email-outbox.notifications.retry_queued'))->success()->send();
                    }),
            ])
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
                            : __('filament/pages/email-outbox.notifications.bulk_cancelled', ['count' => $cancelled]);

                        $this->dispatch('outbox:changed');
                        Notification::make()->title($title)->success()->send();
                    }),
            ]);
    }

    public function render(): View
    {
        return view('email-integration::livewire.table');
    }

    /**
     * @return Builder<Email>
     */
    private function buildQuery(): Builder
    {
        return Email::query()
            ->with(['participants'])
            ->where('team_id', filament()->getTenant()?->getKey())
            ->where('user_id', auth()->id())
            ->where('direction', EmailDirection::OUTBOUND)
            ->when(
                $this->lockedStatus instanceof EmailStatus,
                fn (Builder $query): Builder => $query->where('status', $this->lockedStatus),
            );
    }

    private function statusFilter(): SelectFilter
    {
        return SelectFilter::make('status_tab')
            ->options($this->statusFilterOptions())
            ->default(OutboxTab::QUEUED->value)
            ->selectablePlaceholder(false)
            ->query(fn (Builder $query, array $data): Builder => $this->applyStatusTab(
                $query,
                OutboxTab::tryFrom((string) ($data['value'] ?? '')) ?? OutboxTab::QUEUED,
            ));
    }

    /**
     * @return array<string, string>
     */
    private function statusFilterOptions(): array
    {
        return collect(OutboxTab::cases())
            ->reject(fn (OutboxTab $tab): bool => ! $this->includeFailedFilter && $tab === OutboxTab::FAILED)
            ->mapWithKeys(fn (OutboxTab $tab): array => [$tab->value => $tab->getLabel()])
            ->all();
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function applyStatusTab(Builder $query, OutboxTab $tab): Builder
    {
        return match ($tab) {
            OutboxTab::SCHEDULED => $query->where('status', EmailStatus::QUEUED)
                ->whereNotNull('scheduled_for')->where('scheduled_for', '>', now()),
            OutboxTab::QUEUED => $query->where('status', EmailStatus::QUEUED)
                ->where(fn (Builder $dueQuery): Builder => $dueQuery->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now())),
            OutboxTab::SENDING => $query->where('status', EmailStatus::SENDING),
            OutboxTab::FAILED => $query->where('status', EmailStatus::FAILED),
            OutboxTab::SENT => $query->where('status', EmailStatus::SENT)->where('sent_at', '>=', now()->subDay()),
        };
    }
}
