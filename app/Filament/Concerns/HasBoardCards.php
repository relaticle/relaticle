<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\SaveBoardState;
use App\Actions\Views\SetCardAttributes;
use App\Data\BoardStateData;
use App\Enums\BoardCardSize;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Models\CustomField;
use App\Models\SavedView;
use App\Queries\Views\BoardCardAttributesQuery;
use Closure;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\TextSize;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\Flowforge\Components\CardFlex;

trait HasBoardCards
{
    private const array CARD_RELATIONS = ['company' => 'company.media', 'contact' => 'contact', 'creator' => 'creator', 'assignees' => 'assignees'];

    /** @var list<string>|null */
    private ?array $cardAttributeKeyList = null;

    /** @var Collection<string, CustomField>|null */
    private ?Collection $cardAttributeFieldList = null;

    public function addCardAttribute(mixed $key = null): void
    {
        if (! is_string($key) || ! in_array($key, $this->offeredCardAttributes(), true)) {
            return;
        }

        $this->changeCardAttributes(static fn (array $stored): array => [...$stored, $key]);
    }

    public function removeCardAttribute(mixed $key = null): void
    {
        if (is_string($key)) {
            $this->changeCardAttributes(static fn (array $stored): array => array_values(array_diff($stored, [$key])));
        }
    }

    public function reorderCardAttributes(mixed $keys = null): void
    {
        if (! is_array($keys) || ! array_all($keys, static fn (mixed $key): bool => is_string($key))) {
            return;
        }

        $this->changeCardAttributes(static fn (array $stored): array => BoardStateData::reorderColumns($stored, array_values(array_intersect(array_unique($keys), $stored))));
    }

    /**
     * @param  Closure(list<string>): list<string>  $change
     */
    private function changeCardAttributes(Closure $change): void
    {
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetCardAttributes::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $change, $view));

        if ($saved instanceof BoardStateData) {
            $this->boardStateWritten($saved);
        }
    }

    /**
     * @return list<string>
     */
    private function offeredCardAttributes(): array
    {
        return resolve(BoardCardAttributesQuery::class)->get($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity());
    }

    public function setBoardCardSize(mixed $size = null): void
    {
        $size = is_string($size) ? BoardCardSize::tryFrom($size) : null;

        if ($size instanceof BoardCardSize && $size !== $this->storedBoardState()->cardSize) {
            $this->saveCardSetting('card_size', $size->value);
        }
    }

    public function setCardLabels(mixed $show = false): void
    {
        $this->saveCardSetting('show_labels', filter_var($show, FILTER_VALIDATE_BOOL));
    }

    public function setHideEmptyAttributes(mixed $hide = true): void
    {
        $this->saveCardSetting('hide_empty', filter_var($hide, FILTER_VALIDATE_BOOL));
    }

    protected function attributeEntity(): CrmEntity
    {
        return $this->viewSwitcherEntity();
    }

    protected function attributeCreated(CustomField $field): void
    {
        if (CustomFieldType::tryFrom($field->type)?->fitsOnCard() !== true) {
            Notification::make()->success()->title(__('filament/pages/boards.view_settings.attribute_created_off_card', ['name' => e($field->name)]))->send();

            return;
        }

        $this->addCardAttribute(FilterFieldCatalog::CUSTOM_FIELD_PREFIX.$field->code);
        $this->notifyTableAttributeAdded($field);
    }

    /**
     * @return array{cardAttributes: list<array{key: string, label: string, icon: string}>, addableAttributes: list<array{key: string, label: string, icon: string}>, entityLabel: string, showLabels: bool, hideEmpty: bool, canCreateAttribute: bool}
     */
    protected function cardSettingsView(): array
    {
        $shown = $this->cardAttributeKeys();
        $offered = $this->offeredCardAttributes();
        $columnKey = FilterFieldCatalog::CUSTOM_FIELD_PREFIX.$this->boardColumnField()->code;
        $state = $this->storedBoardState();

        return [
            'cardAttributes' => array_map($this->cardAttributeRow(...), $shown),
            'addableAttributes' => array_map($this->cardAttributeRow(...), array_values(array_diff($offered, $shown, [$columnKey]))),
            'entityLabel' => Str::lower(static::getResource()::getModelLabel()),
            'showLabels' => $state->showLabels,
            'hideEmpty' => $state->hideEmpty,
            'canCreateAttribute' => $this->canCreateTableAttribute(),
        ];
    }

    /**
     * @return array{key: string, label: string, icon: string}
     */
    private function cardAttributeRow(string $key): array
    {
        return ['key' => $key, 'label' => $this->cardAttributeLabel($key), 'icon' => $this->cardAttributeIcon($key)];
    }

    private function saveCardSetting(string $key, bool|string $value): void
    {
        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SaveBoardState::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), [$key => $value], $view));

        if ($saved instanceof BoardStateData) {
            $this->boardStateWritten($saved);
        }
    }

    /**
     * @return list<string>
     */
    protected function cardAttributeKeys(): array
    {
        return $this->cardAttributeKeyList ??= $this->resolveCardAttributeKeys();
    }

    /**
     * @return list<string>
     */
    private function resolveCardAttributeKeys(): array
    {
        $entity = $this->viewSwitcherEntity();
        $chosen = $this->storedBoardState()->cardAttributes ?? $entity->defaultCardAttributes();
        $offered = $this->offeredCardAttributes();
        $columnKey = FilterFieldCatalog::CUSTOM_FIELD_PREFIX.$this->boardColumnField()->code;

        return array_values(array_filter(
            array_unique($chosen),
            static fn (string $key): bool => $key !== $columnKey && in_array($key, $offered, true),
        ));
    }

    /**
     * @return Collection<string, CustomField>
     */
    protected function cardAttributeFields(): Collection
    {
        return $this->cardAttributeFieldList ??= resolve(BoardCardAttributesQuery::class)->fields(
            $this->viewSwitcherUser(),
            $this->viewSwitcherWorkspace(),
            $this->viewSwitcherEntity(),
        );
    }

    protected function forgetBoardCards(): void
    {
        $this->cardAttributeKeyList = null;
        $this->cardAttributeFieldList = null;
    }

    /**
     * @return list<string>
     */
    protected function cardEagerLoads(): array
    {
        if ($this->storedBoardState()->cardSize === BoardCardSize::Compact) {
            return [];
        }

        return array_values(array_intersect_key(self::CARD_RELATIONS, array_flip($this->cardAttributeKeys())));
    }

    protected function cardAttributeLabel(string $key): string
    {
        $field = $this->cardCustomField($key);

        return match (true) {
            $field instanceof CustomField => $field->name,
            $key === 'description' => __('filament/pages/boards.card_attributes.description'),
            $key === 'checklist' => __('filament/pages/boards.card_attributes.checklist'),
            default => $this->cardNativeField($key)->label ?? $key,
        };
    }

    protected function cardAttributeIcon(string $key): string
    {
        $field = $this->cardCustomField($key);

        return match (true) {
            $field instanceof CustomField => $field->typeIcon(),
            $key === 'description' => 'lucide-text-align-start',
            $key === 'checklist' => 'lucide-circle-check',
            default => $this->cardNativeField($key)->icon ?? 'lucide-text-align-start',
        };
    }

    private function cardCustomField(string $key): ?CustomField
    {
        return str_starts_with($key, FilterFieldCatalog::CUSTOM_FIELD_PREFIX)
            ? $this->cardAttributeFields()->get(substr($key, strlen(FilterFieldCatalog::CUSTOM_FIELD_PREFIX)))
            : null;
    }

    private function cardNativeField(string $key): ?FilterField
    {
        $fields = FilterFieldCatalog::for($this->viewSwitcherEntity(), $this->viewSwitcherUser());

        return $fields[$key] ?? null;
    }

    /**
     * @return list<Component>
     */
    protected function boardCardComponents(): array
    {
        $components = $this->cardLeadingComponents();

        if ($this->storedBoardState()->cardSize === BoardCardSize::Compact) {
            return $components;
        }

        $packageEntries = $this->cardPackageEntries();
        $blocks = $this->blockCardAttributes();
        $stacked = $this->stackedCardAttributes();
        $state = $this->storedBoardState();
        $body = [];
        $inline = [];

        foreach ($this->cardAttributeKeys() as $key) {
            $entry = $this->cardEntry($key, $packageEntries->get($key)) ?? $this->cardNativeEntry($key) ?? $this->cardCustomEntry($packageEntries->get($key));

            if (! $entry instanceof Component) {
                continue;
            }

            $block = in_array($key, $blocks, true);

            if ($state->showLabels) {
                $body = [...$body, ...$this->flushInlineCardUnits($inline), $this->cardRow($key, $entry, in_array($key, $stacked, true), $state)];
                $inline = [];

                continue;
            }

            $emptyRow = $state->hideEmpty ? null : $this->cardRow($key, null, in_array($key, $stacked, true), $state);

            if ($block) {
                $body = [...$body, ...$this->flushInlineCardUnits($inline), $entry, ...($emptyRow instanceof CardFlex ? [$emptyRow] : [])];
                $inline = [];

                continue;
            }

            $inline = [...$inline, $entry, ...($emptyRow instanceof CardFlex ? [$emptyRow] : [])];
        }

        $body = [...$body, ...$this->flushInlineCardUnits($inline)];

        if ($state->hideEmpty && ! $state->showLabels) {
            return [...$components, ...$body];
        }

        return [...$components, CardFlex::make($body)->wrap(false)->align('stretch')->extraAttributes(['class' => 'flex-col! gap-y-2'])];
    }

    /**
     * @param  list<Component>  $units
     * @return list<Component>
     */
    private function flushInlineCardUnits(array $units): array
    {
        return $units === [] ? [] : [CardFlex::make($units)->align('center')];
    }

    private function cardRow(string $key, ?Component $entry, bool $stacked, BoardStateData $state): CardFlex
    {
        $field = $this->cardCustomField($key);
        $shown = fn (Model $record): bool => $state->showLabels
            ? ! $state->hideEmpty || ! $this->cardAttributeIsEmpty($record, $key, $field)
            : $this->cardAttributeIsEmpty($record, $key, $field);

        $row = CardFlex::make([
            $this->cardLabel($key, $shown),
            $this->cardEmptyText($key, $state->hideEmpty),
            ...($entry instanceof Component ? [$entry] : []),
        ])->visible($shown);

        return $stacked
            ? $row->wrap(false)->align('stretch')->extraAttributes(['class' => 'flex-col! gap-y-0.5'])
            : $row->align('center')->extraAttributes(['class' => 'fi-board-card-row']);
    }

    /**
     * @return Collection<string, Entry>
     */
    private function cardPackageEntries(): Collection
    {
        $codes = $this->cardAttributeFields()->keys()
            ->filter(fn (string $code): bool => in_array(FilterFieldCatalog::CUSTOM_FIELD_PREFIX.$code, $this->cardAttributeKeys(), true))
            ->values()
            ->all();

        if ($codes === []) {
            return new Collection;
        }

        /** @var Collection<string, Entry> */
        return CustomFields::infolist()
            ->forModel($this->viewSwitcherEntity()->model())
            ->only($codes)
            ->hiddenLabels()
            ->visibleWhenFilled()
            ->withoutSections()
            ->values()
            ->keyBy(fn (mixed $entry): string => $entry->getName());
    }

    private function cardCustomEntry(?Entry $entry): ?Entry
    {
        if (! $entry instanceof Entry) {
            return null;
        }

        if ($entry instanceof TextEntry) {
            return $entry->size(TextSize::ExtraSmall)->wrap()->grow();
        }

        return $entry->grow(false);
    }

    private function cardNativeEntry(string $key): ?Component
    {
        return match ($key) {
            'contact' => RecordChipEntry::make('contact.name')
                ->chipSize('sm')
                ->hiddenLabel()
                ->visible(fn (?string $state): bool => filled($state))
                ->color('gray')
                ->size(TextSize::ExtraSmall)
                ->grow(false),
            'creator' => TextEntry::make('creator.name')
                ->hiddenLabel()
                ->badge()
                ->color('gray')
                ->icon('lucide-circle-user')
                ->grow(false)
                ->visible(fn (?string $state): bool => filled($state)),
            'created_at', 'updated_at' => TextEntry::make($key)
                ->hiddenLabel()
                ->badge()
                ->color('gray')
                ->icon($key === 'created_at' ? 'lucide-calendar' : 'lucide-clock')
                ->dateTime('M j, Y', FilamentTimezone::get())
                ->grow(false)
                ->visible(fn (mixed $state): bool => filled($state)),
            default => null,
        };
    }

    private function cardLabel(string $key, Closure $shown): Text
    {
        $label = $this->cardAttributeLabel($key);

        return Text::make($label)
            ->color('gray')
            ->size(TextSize::ExtraSmall)
            ->extraAttributes(['class' => 'block truncate', 'title' => $label])
            ->grow(false)
            ->visible($shown);
    }

    private function cardEmptyText(string $key, bool $hideEmpty): Text
    {
        $field = $this->cardCustomField($key);

        return Text::make(__('filament/pages/boards.card_attributes.empty'))
            ->color('gray')
            ->size(TextSize::ExtraSmall)
            ->grow(false)
            ->visible(fn (Model $record): bool => ! $hideEmpty && $this->cardAttributeIsEmpty($record, $key, $field));
    }

    private function cardAttributeIsEmpty(Model $record, string $key, ?CustomField $field): bool
    {
        return match (true) {
            $field instanceof CustomField && $record instanceof HasCustomFields => blank($record->getCustomFieldValue($field)),
            $key === 'created_at', $key === 'updated_at' => false,
            array_key_exists($key, self::CARD_RELATIONS) => blank($record->getRelationValue($key)),
            default => $this->derivedCardAttributeIsEmpty($record, $key),
        };
    }

    protected function derivedCardAttributeIsEmpty(Model $record, string $key): bool
    {
        return false;
    }

    /**
     * @return list<Component>
     */
    protected function cardLeadingComponents(): array
    {
        return [];
    }

    abstract protected function cardEntry(string $key, ?Entry $customEntry): ?Component;

    /**
     * @return list<string>
     */
    abstract protected function blockCardAttributes(): array;

    /**
     * @return list<string>
     */
    protected function stackedCardAttributes(): array
    {
        return [];
    }
}
