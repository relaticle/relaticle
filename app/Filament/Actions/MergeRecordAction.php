<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Merge\CombineRecordValues;
use App\Actions\Merge\MergeCompanies;
use App\Actions\Merge\MergePeople;
use App\Data\MergedValues;
use App\Enums\CrmEntity;
use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Support\LikePattern;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;

final class MergeRecordAction extends Action
{
    private const array COUNT_ICONS = [
        'people' => 'lucide-users',
        'opportunities' => 'lucide-circle-dollar-sign',
        'contact_opportunities' => 'lucide-circle-dollar-sign',
        'notes' => 'lucide-file-text',
        'tasks' => 'lucide-clipboard-check',
    ];

    /** @var array<string, array{other: Company|People, current: Company|People, values: MergedValues}|null> */
    private array $pairings = [];

    private Company|People|null $currentWithCounts = null;

    public static function getDefaultName(): string
    {
        return 'mergeRecord';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/record-page.actions.merge_record'))
            ->icon('lucide-merge')
            ->authorize(fn (Model $record): bool => ($record instanceof Company || $record instanceof People)
                && Gate::allows('update', $record)
                && Gate::allows('delete', $record))
            ->modalHeading(fn (): Htmlable => $this->heading())
            ->modalWidth(Width::FourExtraLarge)
            ->stickyModalFooter()
            ->modalFooterActionsAlignment(Alignment::End)
            ->modalSubmitAction(fn (Action $action): Action => $this->submitButton($action))
            ->modalCancelAction(fn (Action $action): Action => $action->label($this->buttonLabel($this->getModalCancelActionLabel(), __('filament/record-page.merge.keys.escape'))))
            ->schema([
                Hidden::make('duplicate_id')->required()->validationAttribute(__('filament/record-page.merge.record')),
                Hidden::make('keep')->default('current'),
                Hidden::make('search')->dehydrated(false),
                View::make('filament.app.merge-record')->viewData(fn (): array => $this->dialog()),
            ])
            ->successNotificationTitle(__('filament/record-page.notifications.records_merged'))
            ->action(function (array $data, ViewRecord $livewire): void {
                try {
                    $kept = $this->merge($data);
                } catch (ValidationException $exception) {
                    $index = array_key_last($livewire->mountedActions);

                    throw ValidationException::withMessages([
                        "mountedActions.{$index}.data.duplicate_id" => $exception->errors()['duplicate_id'] ?? Arr::flatten($exception->errors()),
                    ]);
                }

                $this->success();

                $url = $livewire::getResource()::getUrl('view', ['record' => $kept]);

                $livewire->redirect($url, navigate: FilamentView::hasSpaMode($url));
            });
    }

    private function heading(): Htmlable
    {
        $subject = $this->subject();

        return new HtmlString(view('filament.app.merge-record.heading', [
            'name' => $subject->name,
            'logo' => $this->logo($subject),
            'icon' => $this->entity()->icon(),
        ])->render());
    }

    private function submitButton(Action $action): Action
    {
        return $action
            ->disabled($this->isBlocked())
            ->icon('lucide-merge')
            ->label($this->buttonLabel(__('filament/record-page.merge.submit'), __('filament/record-page.merge.keys.enter'), onPrimary: true))
            ->extraAttributes([
                'class' => 'aria-disabled:pointer-events-auto aria-disabled:cursor-not-allowed aria-disabled:opacity-50',
                'x-on:click' => "if (\$el.getAttribute('aria-disabled') === 'true') { \$event.preventDefault(); \$event.stopImmediatePropagation() }",
            ]);
    }

    private function buttonLabel(string $label, string $key, bool $onPrimary = false): Htmlable
    {
        return new HtmlString(view('filament.app.merge-record.button-label', ['label' => $label, 'key' => $key, 'onPrimary' => $onPrimary])->render());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merge(array $data): Company|People
    {
        $current = $this->subject();
        $id = $data['duplicate_id'] ?? null;
        $other = is_string($id) ? $this->entities()->whereKey($id)->first() : null;

        if ($other === null || $current->is($other)) {
            throw ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')]);
        }

        [$kept, $duplicate] = ($data['keep'] ?? 'current') === 'other' ? [$other, $current] : [$current, $other];

        /** @var User $user */
        $user = Filament::auth()->user();

        return match (true) {
            $kept instanceof People && $duplicate instanceof People => resolve(MergePeople::class)->execute($user, $kept, $duplicate),
            $kept instanceof Company && $duplicate instanceof Company => resolve(MergeCompanies::class)->execute($user, $kept, $duplicate),
            default => abort(404),
        };
    }

    private function subject(): Company|People
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Company || $record instanceof People, 404);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function dialog(): array
    {
        $pairing = $this->pairing();

        if ($pairing === null && $this->formState('keep') === 'other') {
            $this->resetKeep();
        }

        $keepsOther = $pairing !== null && $this->formState('keep') === 'other';

        $current = $this->card($this->currentRecord());
        $other = $pairing === null ? null : $this->card($pairing['other']);

        return [
            'statePath' => $this->statePath(),
            'search' => (string) $this->formState('search'),
            'searchPlaceholder' => $this->subject() instanceof People
                ? __('filament/record-page.merge.search_placeholder.people')
                : __('filament/record-page.merge.search_placeholder.company'),
            'results' => $pairing === null ? $this->searchResults() : [],
            'left' => $keepsOther ? $current : $other,
            'right' => $keepsOther ? $other : $current,
            'keepsOther' => $keepsOther,
            'result' => $pairing === null ? $current : $this->resultCard($pairing),
            'overLimit' => $this->overLimitMessages(),
        ];
    }

    private function isBlocked(): bool
    {
        $pairing = $this->pairing();

        return $pairing === null || $pairing['values']->overLimit !== [];
    }

    /**
     * @return array{other: Company|People, current: Company|People, values: MergedValues}|null
     */
    private function pairing(): ?array
    {
        $id = $this->formState('duplicate_id');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $keepsOther = $this->formState('keep') === 'other';

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
        $kept = $this->formState('keep') === 'other' ? $pairing['other'] : $pairing['current'];
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
     * @return list<array{id: mixed, name: string, detail: ?string, logo: ?string, icon: string}>
     */
    private function searchResults(): array
    {
        $search = (string) $this->formState('search');

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
            'icon' => $this->entity()->icon(),
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

    /**
     * @return list<string>
     */
    private function overLimitMessages(): array
    {
        $overLimit = $this->pairing()['values']->overLimit ?? [];

        if ($overLimit === []) {
            return [];
        }

        return array_values(array_map(
            fn (array $over): string => __('filament/record-page.merge.over_limit', ['field' => $over['label'], 'max' => $over['limit']]),
            $overLimit,
        ));
    }

    private function formState(string $key): mixed
    {
        return data_get($this->mountedActions(), array_key_last($this->mountedActions()).".data.{$key}");
    }

    private function resetKeep(): void
    {
        $livewire = $this->getLivewire();
        abort_unless($livewire instanceof ViewRecord, 404);

        $livewire->mountedActions[array_key_last($livewire->mountedActions)]['data']['keep'] = 'current';
    }

    private function statePath(): string
    {
        return 'mountedActions.'.array_key_last($this->mountedActions()).'.data';
    }

    /**
     * @return array<int|string, mixed>
     */
    private function mountedActions(): array
    {
        $livewire = $this->getLivewire();
        abort_unless($livewire instanceof ViewRecord, 404);

        return $livewire->mountedActions;
    }
}
