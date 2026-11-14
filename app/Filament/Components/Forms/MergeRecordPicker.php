<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Actions\Merge\CombineRecordValues;
use App\Data\MergedValues;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Models\People;
use App\Support\LikePattern;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Components\Attributes\Exposed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Livewire\Attributes\Renderless;

final class MergeRecordPicker extends Field
{
    private const array COUNT_ICONS = [
        'people' => 'lucide-users',
        'opportunities' => 'lucide-circle-dollar-sign',
        'contact_opportunities' => 'lucide-circle-dollar-sign',
        'notes' => 'lucide-file-text',
        'tasks' => 'lucide-clipboard-check',
    ];

    protected string $view = 'filament.app.merge-record';

    /** @var array<string, array{other: Company|People, current: Company|People, values: MergedValues}|null> */
    private array $pairings = [];

    private Company|People|null $currentWithCounts = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->required()
            ->validationAttribute(__('filament/record-page.merge.record'))
            ->afterStateUpdated(fn (Set $set): mixed => $set('keep', 'current'));
    }

    /**
     * @return list<array{id: mixed, name: string, detail: ?string, logo: ?string}>
     */
    #[Exposed]
    #[Renderless]
    public function searchRecords(string $search = ''): array
    {
        $records = $this->searchQuery()
            ->whereKeyNot($this->subject()->getKey())
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $match): Builder => $this->matching($match, $search)))
            ->orderBy('name')
            ->limit(25)
            ->get();

        return array_values($records->map(fn (Company|People $record): array => [
            'id' => $record->getKey(),
            'name' => $record->name,
            'detail' => $this->details($record)[0]['label'] ?? null,
            'logo' => $this->logo($record),
        ])->all());
    }

    public function isBlocked(): bool
    {
        $pairing = $this->pairing();

        return $pairing === null || $pairing['values']->overLimit !== [];
    }

    public function keepsOther(): bool
    {
        return $this->pairing() !== null && $this->keep() === 'other';
    }

    /**
     * @return array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}|null
     */
    public function getLeftCard(): ?array
    {
        return $this->keepsOther() ? $this->card($this->currentRecord()) : $this->otherCard();
    }

    /**
     * @return array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}|null
     */
    public function getRightCard(): ?array
    {
        return $this->keepsOther() ? $this->otherCard() : $this->card($this->currentRecord());
    }

    /**
     * @return array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
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
        return $this->subject() instanceof People
            ? __('filament/record-page.merge.search_placeholder.people')
            : __('filament/record-page.merge.search_placeholder.company');
    }

    public function getEntityIcon(): string
    {
        return $this->entity()->icon();
    }

    private function keep(): mixed
    {
        return ($this->makeGetUtility())('keep');
    }

    /**
     * @return array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}|null
     */
    private function otherCard(): ?array
    {
        $pairing = $this->pairing();

        return $pairing === null ? null : $this->card($pairing['other']);
    }

    /**
     * @return array{other: Company|People, current: Company|People, values: MergedValues}|null
     */
    private function pairing(): ?array
    {
        $id = $this->getState();

        if (! is_string($id) || $id === '') {
            return null;
        }

        $keepsOther = $this->keep() === 'other';

        $key = "{$id}|{$keepsOther}";

        if (! array_key_exists($key, $this->pairings)) {
            $this->pairings[$key] = $this->buildPairing($id, $keepsOther);
        }

        return $this->pairings[$key];
    }

    /**
     * @return array{other: Company|People, current: Company|People, values: MergedValues}|null
     */
    private function buildPairing(string $id, bool $keepsOther): ?array
    {
        $current = $this->currentRecord();
        $other = $this->entities()->whereKey($id)->whereKeyNot($current->getKey())->first();

        if ($other === null) {
            return null;
        }

        $values = resolve(CombineRecordValues::class)
            ->execute(...$keepsOther ? [$other, $current] : [$current, $other]);

        return ['other' => $other, 'current' => $current, 'values' => $values];
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
        if ($this->subject() instanceof People) {
            return People::query()
                ->with(['customFieldValues.customField'])
                ->withCount(['notes', 'tasks'])
                ->addSelect(['contact_opportunities_count' => Opportunity::query()
                    ->selectRaw('count(*)')
                    ->whereColumn('contact_id', 'people.id')]);
        }

        return Company::query()
            ->with(['customFieldValues.customField'])
            ->withCount(['people', 'opportunities', 'notes', 'tasks']);
    }

    /**
     * @return list<string>
     */
    private function countKeys(): array
    {
        return $this->subject() instanceof People
            ? ['contact_opportunities', 'notes', 'tasks']
            : ['people', 'opportunities', 'notes', 'tasks'];
    }

    private function detailCode(): string
    {
        return $this->subject() instanceof People ? PeopleField::EMAILS->value : CompanyField::DOMAINS->value;
    }

    private function logo(Company|People $record): ?string
    {
        return $record instanceof Company ? $record->logo : null;
    }

    private function entity(): CrmEntity
    {
        return $this->subject() instanceof People ? CrmEntity::People : CrmEntity::Company;
    }

    /**
     * @return array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
     */
    private function card(Company|People $record): array
    {
        return $this->cardData(
            $record->name,
            $this->logo($record),
            $this->details($record),
            fn (string $key): int => (int) $record->getAttribute("{$key}_count"),
        );
    }

    /**
     * @param  array{other: Company|People, current: Company|People, values: MergedValues}  $pairing
     * @return array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
     */
    private function resultCard(array $pairing): array
    {
        $values = $pairing['values'];
        $kept = $this->keep() === 'other' ? $pairing['other'] : $pairing['current'];
        $dropped = $kept === $pairing['other'] ? $pairing['current'] : $pairing['other'];

        return $this->cardData(
            (string) $values->attributes['name'],
            $kept instanceof Company && $dropped instanceof Company ? $kept->logo ?? $dropped->logo : null,
            $this->links($values->customFields[$this->detailCode()] ?? []),
            fn (string $key): int => (int) $pairing['other']->getAttribute("{$key}_count") + (int) $pairing['current']->getAttribute("{$key}_count"),
        );
    }

    /**
     * @param  list<array{label: string, href: string}>  $details
     * @param  callable(string): int  $count
     * @return array{name: string, logo: ?string, icon: string, detailIcon: string, details: list<array{label: string, href: string}>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
     */
    private function cardData(string $name, ?string $logo, array $details, callable $count): array
    {
        return [
            'name' => $name,
            'logo' => $logo,
            'icon' => $this->entity()->icon(),
            'detailIcon' => $this->subject() instanceof People ? 'lucide-at-sign' : 'lucide-globe',
            'details' => $details,
            'emptyDetails' => $this->emptyDetails(),
            'counts' => $this->counts($count),
        ];
    }

    /**
     * @param  callable(string): int  $count
     * @return list<array{key: string, icon: string, count: int}>
     */
    private function counts(callable $count): array
    {
        return array_map(
            fn (string $key): array => ['key' => $key, 'icon' => self::COUNT_ICONS[$key], 'count' => $count($key)],
            $this->countKeys(),
        );
    }

    private function emptyDetails(): string
    {
        return $this->subject() instanceof People
            ? __('filament/record-page.merge.no_emails')
            : __('filament/record-page.merge.no_domains');
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
            'href' => $this->subject() instanceof People
                ? "mailto:{$item}"
                : (preg_match('#^https?://#i', $item) === 1 ? $item : "https://{$item}"),
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
        $query = $this->subject() instanceof People ? People::query() : Company::query()->with('media');

        return $query
            ->select(['id', 'name'])
            ->with([
                'customFieldValues' => fn (Relation $values): Relation => $values->whereRelation('customField', 'code', $this->detailCode()),
                'customFieldValues.customField',
            ]);
    }
}
