<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Actions\Views\SaveBoardState;
use App\Actions\Views\SetCardAttributes;
use App\Data\BoardStateData;
use App\Enums\BoardCardSize;
use App\Enums\CustomFieldType;
use App\Filament\Components\Infolists\RecordChipEntry;
use App\Filament\Tables\FilterBar\FilterField;
use App\Filament\Tables\FilterBar\FilterFieldCatalog;
use App\Models\CustomField;
use App\Models\SavedView;
use App\Queries\Views\BoardCardAttributesQuery;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Text;
use Filament\Support\Enums\TextSize;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Relaticle\Flowforge\Components\CardFlex;

trait HasBoardCards
{
    /** @var list<string>|null */
    private ?array $cardAttributeKeyList = null;

    /** @var Collection<string, CustomField>|null */
    private ?Collection $cardAttributeFieldList = null;

    public function setCardAttributes(mixed $keys = []): void
    {
        if (! is_array($keys)) {
            return;
        }

        $strings = array_values(array_filter($keys, is_string(...)));

        if (count($strings) !== count($keys)) {
            return;
        }

        $saved = $this->writingBoardState(fn (?SavedView $view): BoardStateData => resolve(SetCardAttributes::class)->execute($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $this->viewSwitcherEntity(), $strings, $view));

        if ($saved instanceof BoardStateData) {
            $this->boardStateWritten($saved);
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

    private function saveCardSetting(string $key, bool $value): void
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
        $offered = resolve(BoardCardAttributesQuery::class)->get($this->viewSwitcherUser(), $this->viewSwitcherWorkspace(), $entity);
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
        $loads = ['company' => 'company.media', 'contact' => 'contact', 'creator' => 'creator', 'assignees' => 'assignees'];

        return array_values(array_intersect_key($loads, array_flip($this->cardAttributeKeys())));
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
            $field instanceof CustomField => CustomFieldType::from($field->type)->icon(),
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
        $inline = [];

        foreach ($this->cardAttributeKeys() as $key) {
            $unit = $this->cardUnit($key, $packageEntries->get($key), in_array($key, $blocks, true));

            if (! $unit instanceof Component) {
                continue;
            }

            if (! in_array($key, $blocks, true)) {
                $inline[] = $unit;

                continue;
            }

            if ($inline !== []) {
                $components[] = $this->inlineCardRow($inline);
                $inline = [];
            }

            $components[] = $unit;
        }

        if ($inline !== []) {
            $components[] = $this->inlineCardRow($inline);
        }

        return $components;
    }

    /**
     * @param  list<Component>  $units
     */
    private function inlineCardRow(array $units): CardFlex
    {
        $state = $this->storedBoardState();

        $row = CardFlex::make($units)->align('center');

        return $state->hideEmpty && ! $state->showLabels ? $row : $row->extraAttributes(['style' => 'column-gap: 0.75rem']);
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

    private function cardUnit(string $key, ?Entry $customEntry, bool $block): ?Component
    {
        $entry = $this->cardEntry($key, $customEntry) ?? $this->cardNativeEntry($key) ?? $this->cardCustomEntry($customEntry);

        $state = $this->storedBoardState();

        if (! $entry instanceof Component || ($state->hideEmpty && ! $state->showLabels)) {
            return $entry;
        }

        return CardFlex::make([
            $this->cardLabel($key, $state->showLabels, $state->hideEmpty),
            $this->cardEmptyText($key, $state->hideEmpty),
            $entry,
        ])->wrap($block)->align('center')->grow(false);
    }

    private function cardCustomEntry(?Entry $entry): ?Entry
    {
        if (! $entry instanceof Entry) {
            return null;
        }

        if ($entry instanceof TextEntry) {
            $entry->size(TextSize::ExtraSmall)->wrap();
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

    private function cardLabel(string $key, bool $showLabels, bool $hideEmpty): Text
    {
        $field = $this->cardCustomField($key);

        return Text::make($this->cardAttributeLabel($key))
            ->color('gray')
            ->size(TextSize::ExtraSmall)
            ->grow(false)
            ->visible(fn (Model $record): bool => $showLabels
                ? ! $hideEmpty || ! $this->cardAttributeIsEmpty($record, $key, $field)
                : $this->cardAttributeIsEmpty($record, $key, $field));
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
            in_array($key, ['company', 'contact', 'creator', 'assignees'], true) => blank($record->getRelationValue($key)),
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
}
