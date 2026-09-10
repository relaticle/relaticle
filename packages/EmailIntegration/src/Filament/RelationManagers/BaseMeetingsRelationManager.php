<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\RelationManagers;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Actions\UnlinkMeetingFromRecordAction;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Filament\Infolists\MeetingDetailInfolist;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

abstract class BaseMeetingsRelationManager extends RelationManager
{
    protected static string $relationship = 'meetings';

    protected static ?string $title = 'Meetings';

    protected static string|\BackedEnum|null $icon = Heroicon::Calendar;

    public function table(Table $table): Table
    {
        return $table
            // `team` is read per row by MeetingPolicy; eager-load it to avoid a lazy load.
            ->modifyQueryUsing(function (Builder $query): Builder {
                $user = auth()->user();

                if ($user instanceof User) {
                    $query->withGlobalScope('visible', new VisibleMeetingScope($user));

                    // Choose from this record's visible copies before pagination. Missing UIDs remain separate.
                    $identity = "COALESCE('uid:' || NULLIF(meetings.ical_uid, ''), 'id:' || meetings.id)";
                    $copies = (clone $query)
                        ->select('meetings.id')
                        ->distinct([DB::raw($identity), 'meetings.starts_at'])
                        ->reorder()
                        ->orderByRaw($identity)
                        ->oldest('meetings.starts_at')
                        ->orderByRaw('CASE WHEN meetings.connected_account_id IN (SELECT id FROM connected_accounts WHERE user_id = ?) THEN 0 ELSE 1 END', [$user->getKey()])
                        ->orderBy('meetings.id');

                    $query->whereIn('meetings.id', $copies);
                }

                if ($this->hidesOwnerMailbox()) {
                    $query->whereRaw('0 = 1');
                }

                return $query->with(['team', 'attendees.contact', 'connectedAccount.user']);
            })
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('starts_at')
                    ->label(__('filament/relation-managers/meetings.columns.starts_at.label'))
                    ->dateTime('M j, Y · g:i a')
                    ->sortable(),
                TextColumn::make('attendees_count')
                    ->counts('attendees')
                    ->label(__('filament/relation-managers/meetings.columns.attendees_count.label')),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('response_status')
                    ->label(__('filament/relation-managers/meetings.columns.response_status.label'))
                    ->state(function (Meeting $record): ?AttendeeResponseStatus {
                        $user = auth()->user();

                        return $user instanceof User
                            ? resolve(MeetingRespondentResolver::class)->viewerResponseStatus($user, $record)
                            : null;
                    })
                    ->badge(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->emptyStateIcon(fn (): Heroicon => $this->hidesOwnerMailbox()
                ? Heroicon::OutlinedShieldCheck
                : Heroicon::Calendar)
            ->emptyStateHeading(fn (): string => ($this->recordMailboxHiddenCopy() ?? [])['heading'] ?? __('filament-tables::table.empty.heading'))
            ->emptyStateDescription(fn (): ?string => ($this->recordMailboxHiddenCopy() ?? [])['description'] ?? null)
            ->filters([
                Filter::make('upcoming')
                    ->query(fn (Builder $query): Builder => $query->where('starts_at', '>=', now())),
                Filter::make('past')
                    ->query(fn (Builder $query): Builder => $query->where('starts_at', '<', now())),
            ])
            ->recordActions([
                MeetingDetailInfolist::viewAction(),
                Action::make('linkToRecord')
                    ->label(__('filament/relation-managers/meetings.actions.link_to_record.label'))
                    ->icon(Heroicon::Link)
                    ->color('gray')
                    ->schema([
                        Select::make('target_type')
                            ->options([
                                'People' => 'Person',
                                'Company' => 'Company',
                                'Opportunity' => 'Opportunity',
                            ])
                            ->required()
                            ->live(),
                        Select::make('target_id')
                            ->options(fn (Get $get): array => $this->searchOptions((string) $get('target_type')))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data, Meeting $record): void {
                        resolve(LinkMeetingToRecordAction::class)
                            ->execute($record, $this->resolveRecord((string) $data['target_type'], (string) $data['target_id']));

                        Notification::make()
                            ->success()
                            ->title(__('filament/relation-managers/meetings.notifications.linked.title'))
                            ->send();
                    }),
                Action::make('unlinkFromRecord')
                    ->label(__('filament/relation-managers/meetings.actions.unlink_from_record.label'))
                    ->icon(Heroicon::LinkSlash)
                    ->color('danger')
                    ->visible(fn (Meeting $record): bool => $record->isLinkedTo($this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->action(function (Meeting $record): void {
                        resolve(UnlinkMeetingFromRecordAction::class)
                            ->execute($record, $this->getOwnerRecord());

                        Notification::make()
                            ->success()
                            ->title(__('filament/relation-managers/meetings.notifications.unlinked.title'))
                            ->send();
                    }),
            ]);
    }

    /** @return array<string, string> */
    private function searchOptions(string $type): array
    {
        // CRM models carry no global tenant scope, so every query here must be
        // constrained to the current tenant. Otherwise the option list (and the
        // resolveRecord lookup below) would expose and link records from other teams.
        $teamId = filament()->getTenant()?->getKey();

        return match ($type) {
            'People' => People::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
            'Company' => Company::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
            'Opportunity' => Opportunity::query()->where('team_id', $teamId)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private function resolveRecord(string $type, string $id): Model
    {
        $teamId = filament()->getTenant()?->getKey();

        return match ($type) {
            'People' => People::query()->where('team_id', $teamId)->findOrFail($id),
            'Company' => Company::query()->where('team_id', $teamId)->findOrFail($id),
            'Opportunity' => Opportunity::query()->where('team_id', $teamId)->findOrFail($id),
            default => throw new \InvalidArgumentException("Unsupported type: {$type}"),
        };
    }

    private function hidesOwnerMailbox(): bool
    {
        return resolve(EmailVisibilityService::class)->hidesRecordMailbox($this->getOwnerRecord());
    }

    /**
     * @return array{heading: string, description: string}|null
     */
    private function recordMailboxHiddenCopy(): ?array
    {
        $record = $this->getOwnerRecord();

        if (! $record instanceof People && ! $record instanceof Company) {
            return null;
        }

        return resolve(EmailVisibilityService::class)->recordMailboxHiddenCopy($record);
    }
}
