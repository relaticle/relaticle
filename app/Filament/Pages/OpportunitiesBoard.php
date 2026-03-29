<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Filament\Resources\OpportunityResource\Forms\OpportunityForm;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Opportunity;
use App\Models\Team;
use BackedEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use League\CommonMark\Exception\InvalidArgumentException;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\BoardPage;
use Relaticle\Flowforge\Column;
use Relaticle\Flowforge\Components\CardFlex;
use Throwable;
use UnitEnum;

final class OpportunitiesBoard extends BoardPage
{
    protected static ?string $navigationLabel = 'Board';

    protected static ?string $title = 'Opportunities';

    protected static ?string $navigationParentItem = 'Opportunities';

    protected static string|null|UnitEnum $navigationGroup = 'Workspace';

    protected static string|null|BackedEnum $navigationIcon = 'heroicon-o-view-columns';

    public function board(Board $board): Board
    {
        $stageField = $this->stageCustomField();
        $valueColumn = $stageField->getValueColumn();

        $customFields = CustomFields::infolist()
            ->forModel(Opportunity::class)
            ->only([OpportunityCustomField::AMOUNT, OpportunityCustomField::CLOSE_DATE])
            ->hiddenLabels()
            ->visibleWhenFilled()
            ->withoutSections()
            ->values()
            ->keyBy(fn (mixed $field): string => $field->getName());

        return $board
            ->query(
                Opportunity::query()
                    ->leftJoin('custom_field_values as cfv', function (JoinClause $join) use ($stageField): void {
                        $join->on('opportunities.id', '=', 'cfv.entity_id')
                            ->where('cfv.custom_field_id', '=', $stageField->getKey());
                    })
                    ->select('opportunities.*', 'cfv.'.$valueColumn)
                    ->with(['company', 'contact'])
            )
            ->recordTitleAttribute('name')
            ->columnIdentifier($valueColumn)
            ->positionIdentifier('order_column')
            ->searchable(['name'])
            ->columns($this->getColumns())
            ->cardSchema(function (Schema $schema) use ($customFields): Schema {
                $amountField = $customFields->get('custom_fields.'.OpportunityCustomField::AMOUNT->value)
                    ?->visible(fn (?string $state): bool => filled($state))
                    ->badge()
                    ->color('success')
                    ->icon(Heroicon::OutlinedCurrencyDollar)
                    ->grow(false)
                    ->hiddenLabel();

                $closeDateField = $customFields->get('custom_fields.'.OpportunityCustomField::CLOSE_DATE->value)
                    ?->visible(fn (?string $state): bool => filled($state))
                    ->badge()
                    ->color('gray')
                    ->icon(Heroicon::OutlinedCalendar)
                    ->grow(false)
                    ->hiddenLabel()
                    ->formatStateUsing(fn (?string $state): string => $this->formatCloseDateBadge($state));

                return $schema
                    ->components([
                        CardFlex::make([
                            TextEntry::make('company.name')
                                ->hiddenLabel()
                                ->visible(fn (?string $state): bool => filled($state))
                                ->icon(Heroicon::OutlinedBuildingOffice)
                                ->color('gray')
                                ->size(TextSize::ExtraSmall)
                                ->grow(),
                        ]),
                        CardFlex::make([
                            $amountField,
                            $closeDateField,
                        ])->align('center'),
                    ]);
            })
            ->columnActions([
                CreateAction::make()
                    ->label('Add Opportunity')
                    ->icon('heroicon-o-plus')
                    ->iconButton()
                    ->modalWidth(Width::Large)
                    ->slideOver(false)
                    ->model(Opportunity::class)
                    ->schema(fn (Schema $schema): Schema => $schema
                        ->components([
                            TextInput::make('name')
                                ->required()
                                ->placeholder('Enter opportunity title')
                                ->columnSpanFull(),
                            Select::make('company_id')
                                ->relationship('company', 'name')
                                ->searchable()
                                ->preload(),
                            Select::make('contact_id')
                                ->relationship('contact', 'name')
                                ->searchable()
                                ->preload(),
                            CustomFields::form()
                                ->except([OpportunityCustomField::STAGE])
                                ->build()
                                ->columnSpanFull()
                                ->columns(1),
                        ])
                        ->columns(2))
                    ->using(function (array $data, CreateAction $action): Opportunity {
                        /** @var Team $currentTeam */
                        $currentTeam = Auth::guard('web')->user()->currentTeam;

                        /** @var Opportunity $opportunity */
                        $opportunity = $currentTeam->opportunities()->create($data);

                        $columnId = $action->getArguments()['column'] ?? null;

                        if (filled($columnId)) {
                            $opportunity->saveCustomFieldValue($this->stageCustomField(), $columnId);
                            $opportunity->order_column = (float) $this->getBoardPositionInColumn($columnId);
                            $opportunity->saveQuietly();
                        }

                        return $opportunity;
                    }),
            ])
            ->cardActions([
                Action::make('edit')
                    ->label('Edit')
                    ->slideOver()
                    ->modalWidth(Width::ExtraLarge)
                    ->icon('heroicon-o-pencil-square')
                    ->schema(OpportunityForm::get(...))
                    ->fillForm(fn (Opportunity $record): array => [
                        'name' => $record->name,
                        'company_id' => $record->company_id,
                        'contact_id' => $record->contact_id,
                    ])
                    ->action(function (Opportunity $record, array $data): void {
                        $record->update($data);
                    }),
                Action::make('delete')
                    ->label('Delete')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(function (Opportunity $record): void {
                        $record->delete();
                    }),
            ])
            ->filters([
                SelectFilter::make('companies')
                    ->label('Company')
                    ->relationship('company', 'name')
                    ->multiple(),
                SelectFilter::make('contacts')
                    ->label('Contact')
                    ->relationship('contact', 'name')
                    ->multiple(),
            ])
            ->filtersFormWidth(Width::Medium)
            ->headerToolbar();
    }

    /**
     * Move card to new position using DecimalPosition service.
     *
     * Overrides the default Flowforge implementation to properly handle
     * custom field columns which are stored in a polymorphic table.
     *
     * @throws Throwable
     */
    public function moveCard(
        string $cardId,
        string $targetColumnId,
        ?string $afterCardId = null,
        ?string $beforeCardId = null
    ): void {
        $board = $this->getBoard();
        $query = $board->getQuery();

        throw_unless($query instanceof Builder, InvalidArgumentException::class, 'Board query not available');

        $card = (clone $query)->find($cardId);
        throw_unless($card, InvalidArgumentException::class, "Card not found: {$cardId}");

        $newPosition = $this->calculatePositionBetweenCards($afterCardId, $beforeCardId, $targetColumnId);

        DB::transaction(function () use ($card, $board, $targetColumnId, $newPosition): void {
            $columnIdentifier = $board->getColumnIdentifierAttribute();
            $columnValue = $this->resolveStatusValue($card, $columnIdentifier, $targetColumnId);
            $positionIdentifier = $board->getPositionIdentifierAttribute();

            $card->update([$positionIdentifier => $newPosition]);

            /** @var Opportunity $card */
            $card->saveCustomFieldValue($this->stageCustomField(), $columnValue);
        });

        $this->dispatch('kanban-card-moved', [
            'cardId' => $cardId,
            'columnId' => $targetColumnId,
            'position' => $newPosition,
        ]);
    }

    /**
     * Get columns for the board.
     *
     * @return array<Column>
     *
     * @throws Exception
     */
    private function getColumns(): array
    {
        return $this->stages()->map(fn (array $stage): Column => Column::make((string) $stage['id'])
            ->color($stage['color'])
            ->label($stage['name'])
        )->toArray();
    }

    private function formatCloseDateBadge(?string $state): string
    {
        if (blank($state)) {
            return '';
        }

        $date = Date::parse($state);

        return match (true) {
            $date->isPast() => $date->format('M j').' (Overdue)',
            $date->isToday() => 'Closes Today',
            $date->isTomorrow() => 'Closes Tomorrow',
            default => $date->format('M j'),
        };
    }

    private function stageCustomField(): ?CustomField
    {
        /** @var CustomField|null */
        return once(fn () => CustomField::query()
            ->forEntity(Opportunity::class)
            ->where('code', OpportunityCustomField::STAGE)
            ->first());
    }

    /**
     * @return Collection<int, array{id: mixed, custom_field_id: mixed, name: mixed, color: string}>
     */
    private function stages(): Collection
    {
        $field = $this->stageCustomField();

        if (! $field instanceof CustomField) {
            return collect();
        }

        $colorsEnabled = $field->settings->enable_option_colors ?? false;

        return $field->options->map(fn (CustomFieldOption $option): array => [
            'id' => $option->getKey(),
            'custom_field_id' => $option->getAttribute('custom_field_id'),
            'name' => $option->getAttribute('name'),
            'color' => $colorsEnabled ? ($option->settings->color ?? 'gray') : 'gray',
        ]);
    }

    public static function canAccess(): bool
    {
        return (new self)->stageCustomField() instanceof CustomField;
    }
}
