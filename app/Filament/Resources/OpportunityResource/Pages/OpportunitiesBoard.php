<?php

declare(strict_types=1);

namespace App\Filament\Resources\OpportunityResource\Pages;

use App\Actions\Opportunity\UpdateOpportunity;
use App\Contracts\OrdersBoardStages;
use App\Data\BoardStateData;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Filament\Actions\MoveCardAction;
use App\Filament\Actions\PreviewRecordAction;
use App\Filament\Components\Forms\RecordSelect;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Concerns\HasBoardStageOrder;
use App\Filament\Concerns\HasBoardViews;
use App\Filament\Concerns\HasViewSwitcher;
use App\Filament\Resources\OpportunityResource;
use App\Filament\Resources\OpportunityResource\Forms\OpportunityForm;
use App\Filament\Tables\FilterBar\ConditionsFilter;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\Flowforge\Board;
use Relaticle\Flowforge\Components\CardFlex;
use Relaticle\Flowforge\Exceptions\CardMoveRejectedException;
use Relaticle\Flowforge\Grouping;
use Relaticle\Flowforge\Lane;
use Relaticle\Flowforge\Modern\BoardResourcePage;

final class OpportunitiesBoard extends BoardResourcePage implements OrdersBoardStages
{
    use HasBoardStageOrder;
    use HasBoardViews;
    use HasViewSwitcher;

    protected static string $resource = OpportunityResource::class;

    public function getTitle(): string
    {
        return __('filament/pages/boards.opportunities.title');
    }

    protected function getHeaderActions(): array
    {
        return $this->prefillingFirstVisibleColumn(OpportunityResource::collectionActions());
    }

    public function board(Board $board): Board
    {
        $columnField = $this->boardColumnField();

        $customFields = CustomFields::infolist()
            ->forModel(Opportunity::class)
            ->only([OpportunityCustomField::AMOUNT, OpportunityCustomField::CLOSE_DATE])
            ->hiddenLabels()
            ->visibleWhenFilled()
            ->withoutSections()
            ->values()
            ->keyBy(fn (mixed $field): string => $field->getName());

        return $board
            ->query($this->boardColumnQuery(Opportunity::query()->with(['company.media', 'contact']), $columnField))
            ->recordTitleAttribute('name')
            ->columnIdentifier($this->boardColumnIdentifier())
            ->positionIdentifier('order_column')
            ->columns($this->boardColumns())
            ->cardSchema(fn (Schema $schema): Schema => $this->cardSchema($schema, $customFields))
            ->createCardAction($this->cardCreation())
            ->cardAction('edit')
            ->cardActions($this->cardActions())
            ->groupings([$this->companyGrouping()])
            ->filters([ConditionsFilter::make($this->healedFilterRules(...))])
            ->deferFilters(false)
            ->filtersLayout(FiltersLayout::Hidden)
            ->headerToolbar();
    }

    /**
     * @param  Collection<string, mixed>  $customFields
     */
    private function cardSchema(Schema $schema, Collection $customFields): Schema
    {
        $amountField = $customFields->get('custom_fields.'.OpportunityCustomField::AMOUNT->value)
            ?->visible(fn (?string $state): bool => filled($state))
            ->badge()
            ->color('success')
            ->icon('lucide-circle-dollar-sign')
            ->grow(false)
            ->hiddenLabel();

        $closeDateField = $customFields->get('custom_fields.'.OpportunityCustomField::CLOSE_DATE->value)
            ?->visible(fn (?string $state): bool => filled($state))
            ->badge()
            ->color('gray')
            ->icon('lucide-calendar')
            ->grow(false)
            ->hiddenLabel()
            ->formatStateUsing(fn (?string $state): string => $this->formatCloseDateBadge($state));

        return $schema
            ->components([
                View::make('filament.app.board-card-preview-trigger')
                    ->viewData(fn (Opportunity $record): array => [
                        'open' => PreviewRecordAction::openForRecord($record),
                    ]),
                CardFlex::make([
                    RecordChipEntry::make('company.name')
                        ->chipSize('sm')
                        ->hiddenLabel()
                        ->visible(fn (?string $state): bool => filled($state))
                        ->color('gray')
                        ->size(TextSize::ExtraSmall)
                        ->grow(),
                ]),
                CardFlex::make([
                    $amountField,
                    $closeDateField,
                ])->align('center'),
            ]);
    }

    public function previewAction(): PreviewRecordAction
    {
        return PreviewRecordAction::make()
            ->page(ViewOpportunity::class)
            ->record(fn (array $arguments): ?Opportunity => once(fn (): ?Opportunity => Opportunity::query()->whereKey($arguments['record'] ?? null)->first()));
    }

    /**
     * @return array<int, Action>
     */
    private function cardActions(): array
    {
        return [
            Action::make('edit')
                ->authorize(fn (?Opportunity $record): bool => $record instanceof Opportunity && Gate::allows('update', $record))
                ->label(__('filament/pages/boards.opportunities.actions.edit'))
                ->slideOver()
                ->modalWidth(Width::ExtraLarge)
                ->icon('lucide-square-pen')
                ->schema(OpportunityForm::get(...))
                ->fillForm(fn (Opportunity $record): array => [
                    'name' => $record->name,
                    'company_id' => $record->company_id,
                    'contact_id' => $record->contact_id,
                ])
                ->action(function (Opportunity $record, array $data): void {
                    $record->update($data);
                }),
            MoveCardAction::make(),
            Action::make('delete')
                ->authorize(fn (?Opportunity $record): bool => $record instanceof Opportunity && Gate::allows('delete', $record))
                ->label(__('filament/pages/boards.opportunities.actions.delete'))
                ->icon('lucide-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (Opportunity $record): void {
                    $record->delete();
                }),
        ];
    }

    protected function authorizeCardMove(Model $card, string $targetColumnId, ?string $toLaneId = null): void
    {
        $this->refuseReorderWhileSorted($card, $targetColumnId);

        if (Gate::denies('update', $card)) {
            throw CardMoveRejectedException::because(__('filament/pages/boards.move_denied'));
        }
    }

    protected function persistCardMove(Model $card, mixed $columnValue, string $position): void
    {
        $card->update([$this->getBoard()->getPositionIdentifierAttribute() => $position]);

        /** @var Opportunity $card */
        $card->saveCustomFieldValue($this->boardColumnField(), $this->columnValueFor((string) $columnValue));
    }

    private function cardCreation(): CreateAction
    {
        return CreateAction::make('addCard')
            ->authorize(fn (): bool => Gate::allows('create', Opportunity::class))
            ->label(__('filament/pages/boards.opportunities.actions.add'))
            ->icon('lucide-plus')
            ->iconButton()
            ->modalWidth(Width::Large)
            ->slideOver(false)
            ->model(Opportunity::class)
            ->schema($this->quickCreateForm(...))
            ->using($this->createCard(...));
    }

    private function companyGrouping(): Grouping
    {
        return Grouping::make('company')
            ->label(__('filament/pages/boards.opportunities.groupings.company'))
            ->lanes(fn (Builder $boardQuery): array => Company::query()
                ->whereIn('companies.id', (clone $boardQuery)->select('opportunities.company_id'))
                ->orderBy('name')
                ->get()
                ->map(fn (Company $company): Lane => Lane::make($company->getKey())
                    ->label($company->name)
                    ->query(fn (Builder $query): Builder => $query->where('opportunities.company_id', $company->getKey())))
                ->all())
            ->emptyLane(
                __('filament/pages/boards.opportunities.groupings.no_company'),
                fn (Builder $query): Builder => $query->whereNull('opportunities.company_id'),
            )
            ->moveUsing(fn (Opportunity $record, Lane $to): Opportunity => resolve(UpdateOpportunity::class)->execute(
                $this->currentUser(),
                $record,
                ['company_id' => $to->getName() === Grouping::EMPTY_LANE ? null : $to->getName()],
            ));
    }

    private function currentUser(): User
    {
        /** @var User */
        return Auth::guard('web')->user();
    }

    private function quickCreateForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required()
                    ->placeholder(__('filament/pages/boards.opportunities.form.name_placeholder'))
                    ->columnSpanFull(),
                RecordSelect::make('company_id')
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                RecordSelect::make('contact_id')
                    ->label(__('filament/resources/opportunity.fields.contact_id.label'))
                    ->relationship('contact', 'name')
                    ->searchable()
                    ->preload(),
                CustomFields::form()
                    ->except([$this->boardColumnField()->code])
                    ->build()
                    ->columnSpanFull()
                    ->columns(1),
            ])
            ->columns(2);
    }

    /**
     * "Today" and "tomorrow" are questions about the viewer's calendar, so the boundary
     * moves into their zone, but the close date itself must not. Unlike the tasks
     * board's due date, this is a plain calendar date the package stores at midnight
     * UTC: converting it into a negative-offset zone walks it back past midnight and
     * the card reads a day early. Move only the "today" it is measured against, and
     * compare the two as dates rather than instants. Midnight UTC and midnight in
     * Los Angeles are the same calendar day but seven hours apart, so an instant
     * comparison would call a date closing today overdue.
     */
    private function formatCloseDateBadge(?string $state): string
    {
        if (blank($state)) {
            return '';
        }

        $date = Date::parse($state);
        $viewerToday = Date::now(FilamentTimezone::get())->startOfDay();

        $closesOn = $date->toDateString();
        $today = $viewerToday->toDateString();
        $tomorrow = $viewerToday->copy()->addDay()->toDateString();

        return match (true) {
            $closesOn < $today => $date->format('M j').' (Overdue)',
            $closesOn === $today => 'Closes Today',
            $closesOn === $tomorrow => 'Closes Tomorrow',
            default => $date->format('M j'),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createCard(array $data, CreateAction $action): Opportunity
    {
        $columnId = $action->getArguments()['column'] ?? null;

        abort_unless(blank($columnId) || $this->ownsColumn((string) $columnId), 422);

        /** @var Workspace $currentWorkspace */
        $currentWorkspace = Auth::guard('web')->user()->currentWorkspace;

        /** @var Opportunity $opportunity */
        $opportunity = $currentWorkspace->opportunities()->create($data);

        if (filled($columnId)) {
            if ($columnId !== BoardStateData::NO_VALUE_COLUMN) {
                $opportunity->saveCustomFieldValue($this->boardColumnField(), $columnId);
            }

            $opportunity->order_column = (float) $this->getBoardPositionInColumn($columnId);
            $opportunity->saveQuietly();
        }

        return $opportunity;
    }

    private static function stageCustomField(): ?CustomField
    {
        /** @var CustomField|null */
        return once(fn () => CustomField::query()
            ->forEntity(Opportunity::class)
            ->where('code', CrmEntity::Opportunity->boardFieldCode())
            ->first());
    }

    protected function defaultBoardColumnField(): ?CustomField
    {
        return self::stageCustomField();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return self::stageCustomField() instanceof CustomField;
    }
}
