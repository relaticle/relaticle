<?php

declare(strict_types=1);

namespace App\Filament\RelationManagers;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Actions\LinkMeetingToRecordAction;
use Relaticle\EmailIntegration\Actions\UnlinkMeetingFromRecordAction;
use Relaticle\EmailIntegration\Models\Meeting;

abstract class BaseMeetingsRelationManager extends RelationManager
{
    protected static string $relationship = 'meetings';

    protected static ?string $title = 'Meetings';

    protected static string|\BackedEnum|null $icon = Heroicon::Calendar;

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->limit(60),
                TextColumn::make('starts_at')
                    ->label('Time')
                    ->dateTime('M j, Y · g:i a')
                    ->sortable(),
                TextColumn::make('attendees_count')
                    ->counts('attendees')
                    ->label('Attendees'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('response_status')
                    ->label('My RSVP')
                    ->badge(),
            ])
            ->defaultSort('starts_at', 'desc')
            ->filters([
                Filter::make('upcoming')
                    ->query(fn (Builder $q): Builder => $q->where('starts_at', '>=', now())),
                Filter::make('past')
                    ->query(fn (Builder $q): Builder => $q->where('starts_at', '<', now())),
            ])
            ->recordActions([
                ViewAction::make()
                    ->schema(fn (Schema $schema): Schema => $this->detailSchema($schema)),
                Action::make('linkToRecord')
                    ->label('Link to record')
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
                            ->title('Meeting linked successfully.')
                            ->send();
                    }),
                Action::make('unlinkFromRecord')
                    ->label('Unlink from this record')
                    ->icon(Heroicon::LinkSlash)
                    ->color('danger')
                    ->visible(fn (Meeting $record): bool => $record->isLinkedTo($this->getOwnerRecord()))
                    ->requiresConfirmation()
                    ->action(function (Meeting $record): void {
                        resolve(UnlinkMeetingFromRecordAction::class)
                            ->execute($record, $this->getOwnerRecord());

                        Notification::make()
                            ->success()
                            ->title('Meeting unlinked successfully.')
                            ->send();
                    }),
            ]);
    }

    protected function detailSchema(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Meeting')->schema([
                TextEntry::make('title'),
                TextEntry::make('starts_at')->dateTime('M j, Y · g:i a'),
                TextEntry::make('ends_at')->dateTime('M j, Y · g:i a'),
                TextEntry::make('location')->default('—'),
                TextEntry::make('organizer_name')->label('Organizer'),
            ]),
            Section::make('Attendees')->schema([
                RepeatableEntry::make('attendees')->schema([
                    TextEntry::make('name')->default(fn (Model $record): string => $record->email_address),  // @phpstan-ignore-line
                    TextEntry::make('email_address')->label('Email'),
                    TextEntry::make('response_status')->badge(),
                ]),
            ]),
            Section::make('Description')->schema([
                TextEntry::make('description')->html()->default('(no description)'),
            ]),
            Section::make('Link')->schema([
                TextEntry::make('html_link')
                    ->label('Open in Google Calendar')
                    ->url(fn (Model $record): ?string => $record->html_link, shouldOpenInNewTab: true),  // @phpstan-ignore-line
            ]),
        ]);
    }

    /** @return array<string, string> */
    private function searchOptions(string $type): array
    {
        return match ($type) {
            'People' => People::query()->pluck('name', 'id')->all(),
            'Company' => Company::query()->pluck('name', 'id')->all(),
            'Opportunity' => Opportunity::query()->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private function resolveRecord(string $type, string $id): Model
    {
        return match ($type) {
            'People' => People::query()->findOrFail($id),
            'Company' => Company::query()->findOrFail($id),
            'Opportunity' => Opportunity::query()->findOrFail($id),
            default => throw new \InvalidArgumentException("Unsupported type: {$type}"),
        };
    }
}
