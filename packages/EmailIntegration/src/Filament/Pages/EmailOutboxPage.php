<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Pages;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Relaticle\EmailIntegration\Actions\CancelQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RescheduleQueuedEmailAction;
use Relaticle\EmailIntegration\Actions\RetryFailedEmailAction;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Enums\OutboxTab;
use Relaticle\EmailIntegration\Models\Email;
use UnitEnum;

final class EmailOutboxPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'email-integration::filament.pages.email-outbox';

    protected static ?string $slug = 'outbox';

    protected static ?string $title = 'Outbox';

    protected static ?int $navigationSort = 5;

    protected static string|UnitEnum|null $navigationGroup = 'Emails';

    public function table(Table $table): Table
    {
        return $table
            ->query($this->buildQuery())
            ->filters([
                SelectFilter::make('status_tab')
                    ->options(OutboxTab::class)
                    ->default(OutboxTab::QUEUED->value)
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => $this->applyStatusTab(
                        $query,
                        OutboxTab::tryFrom((string) ($data['value'] ?? '')) ?? OutboxTab::QUEUED,
                    )),
            ])
            ->columns([
                TextColumn::make('subject')->limit(50)->searchable(),
                TextColumn::make('participants_to')
                    ->label('Recipients')
                    ->state(fn (Email $record): string => $record->participants
                        ->where('role', 'to')->pluck('email_address')->implode(', ')),
                TextColumn::make('status')->badge(),
                TextColumn::make('scheduled_for')->dateTime()->label('Scheduled for'),
                TextColumn::make('priority')->badge(),
                TextColumn::make('last_error')->toggleable(isToggledHiddenByDefault: true)->wrap(),
            ])
            ->recordActions([
                Action::make('cancel')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Email $record): bool => $record->status === EmailStatus::QUEUED)
                    ->action(function (Email $record): void {
                        resolve(CancelQueuedEmailAction::class)->execute($record);
                        Notification::make()->title('Cancelled')->success()->send();
                    }),
                Action::make('reschedule')
                    ->icon(Heroicon::OutlinedClock)
                    ->visible(fn (Email $record): bool => $record->status === EmailStatus::QUEUED)
                    ->schema([
                        DateTimePicker::make('scheduled_for')
                            ->label('Send at')
                            ->seconds(false)
                            ->minDate(now())
                            ->required(),
                    ])
                    ->fillForm(fn (Email $record): array => ['scheduled_for' => $record->scheduled_for])
                    ->action(function (Email $record, array $data): void {
                        resolve(RescheduleQueuedEmailAction::class)->execute($record, Date::parse((string) $data['scheduled_for']));
                        Notification::make()->title('Rescheduled')->success()->send();
                    }),
                Action::make('retry')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('warning')
                    ->visible(fn (Email $record): bool => $record->status === EmailStatus::FAILED)
                    ->action(function (Email $record): void {
                        resolve(RetryFailedEmailAction::class)->execute($record);
                        Notification::make()->title('Retry queued')->success()->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('bulkCancel')
                    ->label('Cancel selected')
                    ->color('danger')
                    ->icon(Heroicon::OutlinedXMark)
                    ->requiresConfirmation()
                    ->action(function (Collection $records): void {
                        $cancelled = 0;
                        foreach ($records as $record) {
                            if ($record instanceof Email && $record->status === EmailStatus::QUEUED) {
                                resolve(CancelQueuedEmailAction::class)->execute($record);
                                $cancelled++;
                            }
                        }
                        Notification::make()->title("Cancelled {$cancelled} emails")->success()->send();
                    }),
            ]);
    }

    /**
     * @return Builder<Email>
     */
    private function buildQuery(): Builder
    {
        return Email::query()
            ->with(['participants'])
            ->where('user_id', auth()->id())
            ->where('direction', EmailDirection::OUTBOUND);
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
