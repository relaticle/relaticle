<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Actions\Merge\CombineRecordValues;
use App\Data\MergedValues;
use App\Enums\CrmEntity;
use App\Enums\RecordIdentity;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Support\LikePattern;
use Filament\Forms\Components\Field;
use Filament\Models\Contracts\HasAvatar;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Renderless;

/**
 * @phpstan-type Card array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
 * @phpstan-type Pairing array{picked: Company|People, current: Company|People, values: MergedValues}
 */
final class MergeRecordPicker extends Field
{
    protected string $view = 'filament.app.merge-record';

    /** @var array<string, Pairing|null> */
    private array $pairings = [];

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
        abort_unless(Gate::allows('update', $subject) && Gate::allows('delete', $subject), 403);

        $records = $this->searchQuery()
            ->whereKeyNot($subject->getKey())
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $this->matching($match, $search)))
            ->orderBy('name')
            ->limit(25)
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
        return $this->pairing() !== null && $this->keepPickedState();
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
        $overLimit = $this->pairing()['values']->overLimit ?? [];

        return array_values(array_map(
            fn (array $over): string => __('filament/record-page.merge.over_limit', ['field' => $over['label'], 'max' => $over['limit']]),
            $overLimit,
        ));
    }

    public function getSearchPlaceholder(): string
    {
        return $this->identity()->searchPlaceholder();
    }

    public function getEntityIcon(): string
    {
        return $this->entity()->icon();
    }

    private function keepPickedState(): bool
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

        $keepsPicked = $this->keepPickedState();

        $key = "{$pickedId}|{$keepsPicked}";

        if (! array_key_exists($key, $this->pairings)) {
            $this->pairings[$key] = $this->buildPairing($pickedId, $keepsPicked);
        }

        return $this->pairings[$key];
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

        $values = resolve(CombineRecordValues::class)
            ->execute(...$keepsPicked ? [$picked, $current] : [$current, $picked]);

        return ['picked' => $picked, 'current' => $current, 'values' => $values];
    }

    private function subject(): Company|People
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Company || $record instanceof People, 404);

        return $record;
    }

    private function currentRecord(): Company|People
    {
        return $this->currentWithCounts ??= $this->entities()->whereKey($this->subject()->getKey())->firstOrFail();
    }

    /**
     * @return Builder<Company>|Builder<People>
     */
    private function entities(): Builder
    {
        return $this->subject()->newQuery()
            ->with(['customFieldValues.customField'])
            ->withCount($this->entity()->mergeCountRelations());
    }

    private function countIcon(string $relation): string
    {
        if ($relation === 'people') {
            return 'lucide-users';
        }

        $related = $this->subject()->{$relation}()->getRelated();

        return (CrmEntity::tryFromModel($related) ?? abort(404))->icon();
    }

    private function detailCode(): string
    {
        return $this->identity()->fieldCode();
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
            fn (string $key): int => (int) $record->getAttribute("{$key}_count"),
        );
    }

    /**
     * @param  Pairing  $pairing
     * @return Card
     */
    private function resultCard(array $pairing): array
    {
        $values = $pairing['values'];
        $kept = $this->keepPickedState() ? $pairing['picked'] : $pairing['current'];
        $dropped = $kept === $pairing['picked'] ? $pairing['current'] : $pairing['picked'];

        return $this->cardData(
            (string) $values->attributes['name'],
            $this->image($kept) ?? $this->image($dropped),
            $this->links($values->customFields[$this->detailCode()] ?? []),
            fn (string $key): int => (int) $pairing['picked']->getAttribute("{$key}_count")
                + (int) $pairing['current']->getAttribute("{$key}_count")
                - $this->sharedCount($key, $pairing),
        );
    }

    /**
     * @param  Pairing  $pairing
     */
    private function sharedCount(string $key, array $pairing): int
    {
        $relation = $this->subject()->{Str::camel($key)}();

        if (! $relation instanceof MorphToMany) {
            return 0;
        }

        $linked = fn (Company|People $record) => $relation->newPivotStatement()
            ->where($relation->getMorphType(), $record->getMorphClass())
            ->where($relation->getForeignPivotKeyName(), $record->getKey());

        return $linked($pairing['picked'])
            ->whereIn($relation->getRelatedPivotKeyName(), $linked($pairing['current'])->select($relation->getRelatedPivotKeyName()))
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
        return array_map(function (string $relation) use ($count): array {
            $key = Str::snake($relation);

            return ['key' => $key, 'icon' => $this->countIcon($relation), 'count' => $count($key)];
        }, $this->entity()->mergeCountRelations());
    }

    /**
     * @return list<array{label: string, href: string}>
     */
    private function details(Company|People $record): array
    {
        $value = $record->customFieldValues
            ->first(fn (CustomFieldValue $fieldValue): bool => $fieldValue->customField->code === $this->detailCode())
            ?->getValue();

        return $this->links($value);
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
        $identity = CustomField::query()
            ->forMorphEntity($this->subject()->getMorphClass())
            ->where('code', $this->detailCode())
            ->first();

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
        return $this->subject()->newQuery()
            ->when($this->subject() instanceof HasAvatar, fn (Builder $query): Builder => $query->with('media'))
            ->select(['id', 'name'])
            ->with([
                'customFieldValues' => fn (Relation $values): Relation => $values->whereRelation('customField', 'code', $this->detailCode()),
                'customFieldValues.customField',
            ]);
    }
}
