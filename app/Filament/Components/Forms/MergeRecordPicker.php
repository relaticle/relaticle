<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Actions\Merge\CombineRecordValues;
use App\Data\LinkOption;
use App\Data\MergedValues;
use App\Enums\CrmEntity;
use App\Enums\RecordIdentity;
use App\Filament\Actions\MergeRecordAction;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Support\CustomFields\WorkspaceCustomFields;
use App\Support\LikePattern;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Models\Contracts\HasAvatar;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;

/**
 * @phpstan-type Card array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
 * @phpstan-type Pairing array{picked: Company|People, priority: Company|People, other: Company|People, values: MergedValues}
 */
final class MergeRecordPicker extends Field
{
    private const int MAX_SEARCH_LENGTH = 100;

    private const int MAX_RESULTS = 25;

    protected string $view = 'filament.app.merge-record';

    private Company|People|null $currentWithCounts = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->required()
            ->validationAttribute(__('filament/record-page.merge.record'))
            ->afterStateUpdated(fn (Set $set): mixed => $set('keep_picked', false));
    }

    /**
     * @return list<array{id: mixed, name: string, detail: ?string, logo: ?string}>
     */
    #[Exposed]
    #[Renderless]
    public function searchRecords(string $search = ''): array
    {
        $subject = $this->subject();
        abort_unless(MergeRecordAction::canMerge($subject), 403);

        $search = mb_substr(trim($search), 0, self::MAX_SEARCH_LENGTH);

        $records = $this->searchQuery()
            ->whereKeyNot($subject->getKey())
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $this->matching($match, $search)))
            ->orderBy('name')
            ->limit(self::MAX_RESULTS)
            ->get();

        return array_values($records->map(function (Company|People $record): array {
            $detail = $this->details($record)[0]['label'] ?? null;

            return [
                'id' => $record->getKey(),
                'name' => $record->name,
                'detail' => $detail === $record->name ? null : $detail,
                'logo' => $this->image($record),
            ];
        })->all());
    }

    public function isBlocked(): bool
    {
        $pairing = $this->pairing();

        return $pairing === null || $pairing['values']->overLimit !== [];
    }

    public function isUnavailable(): bool
    {
        $id = $this->getState();

        return is_string($id) && $id !== '' && $this->pairing() === null;
    }

    public function keepsPicked(): bool
    {
        return $this->pairing() !== null && $this->keepPickedFlag();
    }

    /**
     * @return Card|null
     */
    public function getLeftCard(): ?array
    {
        return $this->keepsPicked() ? $this->card($this->currentRecord()) : $this->pickedCard();
    }

    /**
     * @return Card|null
     */
    public function getRightCard(): ?array
    {
        return $this->keepsPicked() ? $this->pickedCard() : $this->card($this->currentRecord());
    }

    /**
     * @return Card
     */
    public function getResultCard(): array
    {
        $pairing = $this->pairing();

        return $pairing === null ? $this->card($this->currentRecord()) : $this->resultCard($pairing);
    }

    /**
     * @return list<string>
     */
    public function getOverLimitMessages(): array
    {
        return array_values($this->pairing()['values']->overLimit ?? []);
    }

    /**
     * @return list<string>
     */
    public function getRaiseNotices(): array
    {
        $raises = $this->pairing()['values']->raises ?? [];

        if ($raises === []) {
            return [];
        }

        $subject = $this->subject();
        $entity = Str::ucfirst(Filament::getModelResource($subject)::getPluralModelLabel());
        $fields = resolve(WorkspaceCustomFields::class)
            ->forEntity($subject->loadMissing('workspace')->workspace, $subject->getMorphClass())
            ->keyBy('code');

        return array_map(
            fn (int $count, string $code): string => __('filament/record-page.merge.raised', [
                'entity' => $entity,
                'count' => $count,
                'field' => mb_strtolower($fields[$code]->name),
            ]),
            $raises,
            array_keys($raises),
        );
    }

    public function getSearchPlaceholder(): string
    {
        return $this->identity()->searchPlaceholder();
    }

    public function getEntityIcon(): string
    {
        return $this->entity()->icon();
    }

    private function keepPickedFlag(): bool
    {
        return ($this->makeGetUtility())('keep_picked') === true;
    }

    /**
     * @return Card|null
     */
    private function pickedCard(): ?array
    {
        $pairing = $this->pairing();

        return $pairing === null ? null : $this->card($pairing['picked']);
    }

    /**
     * @return Pairing|null
     */
    private function pairing(): ?array
    {
        $pickedId = $this->getState();

        if (! is_string($pickedId) || $pickedId === '') {
            return null;
        }

        $keepsPicked = $this->keepPickedFlag();

        return once(fn (): ?array => $this->buildPairing($pickedId, $keepsPicked));
    }

    /**
     * @return Pairing|null
     */
    private function buildPairing(string $id, bool $keepsPicked): ?array
    {
        $current = $this->currentRecord();
        $picked = $this->entities()->whereKey($id)->whereKeyNot($current->getKey())->first();

        if ($picked === null) {
            return null;
        }

        [$priority, $other] = $keepsPicked ? [$picked, $current] : [$current, $picked];

        return ['picked' => $picked, 'priority' => $priority, 'other' => $other, 'values' => resolve(CombineRecordValues::class)->execute($priority, $other)];
    }

    private function subject(): Company|People
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Company || $record instanceof People, 404);

        return $record;
    }

    private function currentRecord(): Company|People
    {
        return $this->currentWithCounts ??= $this->subject()
            ->loadMissing('customFieldValues.customField')
            ->loadCount(array_keys($this->entity()->mergeCountIcons()));
    }

    /**
     * @return Builder<Company>|Builder<People>
     */
    private function entities(): Builder
    {
        return $this->subject()->newQuery()
            ->with(['customFieldValues.customField'])
            ->withCount(array_keys($this->entity()->mergeCountIcons()));
    }

    private function image(Model $record): ?string
    {
        return $record instanceof HasAvatar ? $record->getFilamentAvatarUrl() : null;
    }

    private function entity(): CrmEntity
    {
        return CrmEntity::tryFromModel($this->subject()) ?? abort(404);
    }

    private function identity(): RecordIdentity
    {
        return $this->entity()->identity() ?? abort(404);
    }

    /**
     * @return Card
     */
    private function card(Company|People $record): array
    {
        return $this->cardData(
            $record->name,
            $this->image($record),
            $this->details($record),
            fn (string $relation): int => (int) $record->getAttribute(Str::snake($relation).'_count'),
        );
    }

    /**
     * @param  Pairing  $pairing
     * @return Card
     */
    private function resultCard(array $pairing): array
    {
        $values = $pairing['values'];
        $picked = $pairing['picked'];
        $current = $this->currentRecord();

        return $this->cardData(
            (string) $values->attributes['name'],
            $this->image($pairing['priority']) ?? $this->image($pairing['other']),
            $this->links($values->customFields[$this->identity()->fieldCode()] ?? []),
            fn (string $relation): int => (int) $picked->getAttribute(Str::snake($relation).'_count')
                + (int) $current->getAttribute(Str::snake($relation).'_count')
                - $this->sharedCount($relation, $pairing),
        );
    }

    /**
     * @param  Pairing  $pairing
     */
    private function sharedCount(string $relation, array $pairing): int
    {
        $currentLinks = $this->currentRecord()->{$relation}();

        if (! $currentLinks instanceof MorphToMany) {
            return 0;
        }

        $relatedKey = $currentLinks->getRelated()->getQualifiedKeyName();

        return $pairing['picked']->{$relation}()
            ->whereIn($relatedKey, $currentLinks->select($relatedKey))
            ->count();
    }

    /**
     * @param  list<array{label: string, href: string}>  $details
     * @param  callable(string): int  $count
     * @return Card
     */
    private function cardData(string $name, ?string $logo, array $details, callable $count): array
    {
        return [
            'name' => $name,
            'logo' => $logo,
            'icon' => $this->entity()->icon(),
            'detailIcon' => $this->identity()->icon(),
            'details' => $details,
            'emptyDetails' => $this->identity()->emptyLabel(),
            'counts' => $this->counts($count),
        ];
    }

    /**
     * @param  callable(string): int  $count
     * @return list<array{key: string, icon: string, count: int}>
     */
    private function counts(callable $count): array
    {
        $icons = $this->entity()->mergeCountIcons();

        return array_map(fn (string $relation, string $icon): array => [
            'key' => Str::snake($relation),
            'icon' => $icon,
            'count' => $count($relation),
        ], array_keys($icons), $icons);
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function details(Company|People $record): array
    {
        $field = $this->identityField();

        return $this->links($field instanceof CustomField ? $record->getCustomFieldValue($field) : null);
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function links(mixed $value): array
    {
        $strings = Collection::wrap($value)->filter(fn (mixed $item): bool => is_string($item) && $item !== '');

        return array_values($strings->map(fn (string $item): array => [
            'label' => $item,
            'href' => $this->identity()->href($item),
        ])->all());
    }

    /**
     * @param  Builder<Company>|Builder<People>  $query
     * @return Builder<Company>|Builder<People>
     */
    private function matching(Builder $query, string $search): Builder
    {
        $identity = $this->identityField();

        $query->where('name', 'ilike', '%'.LikePattern::escape($search).'%');

        if (! $identity instanceof CustomField) {
            return $query;
        }

        return $query->orWhereHas('customFieldValues', static function (Builder $values) use ($identity, $search): void {
            /** @var Builder<CustomFieldValue> $values */
            $values->containingText($identity, $search);
        });
    }

    /**
     * @return Builder<Company>|Builder<People>
     */
    private function searchQuery(): Builder
    {
        $subject = $this->subject();
        $identity = $this->identityField();

        return $subject->newQuery()
            ->with(LinkOption::marks($subject))
            ->select(LinkOption::columns($subject, 'name'))
            ->when($identity instanceof CustomField, fn (Builder $query): Builder => $query->with([
                'customFieldValues' => fn (Relation $values): Relation => $values->where('custom_field_id', $identity->getKey()),
                'customFieldValues.customField',
            ]));
    }

    private function identityField(): ?CustomField
    {
        return once(function (): ?CustomField {
            $subject = $this->subject();

            return resolve(WorkspaceCustomFields::class)
                ->forEntity($subject->loadMissing('workspace')->workspace, $subject->getMorphClass())
                ->firstWhere('code', $this->identity()->fieldCode());
        });
    }
}
