<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Merge\CombineRecordValues;
use App\Actions\Merge\MergeCompanies;
use App\Actions\Merge\MergePeople;
use App\Data\MergedValues;
use App\Enums\CustomFields\CompanyField;
use App\Enums\CustomFields\PeopleField;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
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
            ->visible(fn (Model $record): bool => ($record instanceof Company || $record instanceof People)
                && Gate::allows('update', $record)
                && Gate::allows('delete', $record))
            ->modalHeading(fn (): Htmlable => new HtmlString(view('filament.app.merge-record.heading', ['name' => $this->subject()->name])->render()))
            ->modalWidth(Width::FourExtraLarge)
            ->modalSubmitActionLabel(__('filament/record-page.merge.submit'))
            ->modalSubmitAction(fn (Action $action): Action => $action->disabled($this->isBlocked()))
            ->schema([
                Hidden::make('duplicate_id')->required(),
                Hidden::make('keep')->default('current'),
                Hidden::make('search')->dehydrated(false),
                View::make('filament.app.merge-record')->viewData(fn (): array => $this->dialog()),
            ])
            ->action(function (array $data, ViewRecord $livewire): void {
                try {
                    $kept = $this->merge($data);
                } catch (ValidationException $exception) {
                    $index = array_key_last($livewire->mountedActions);

                    throw ValidationException::withMessages([
                        "mountedActions.{$index}.data.duplicate_id" => $exception->errors()['duplicate_id'],
                    ]);
                }

                Notification::make()->title(__('filament/record-page.merge.merged'))->success()->send();

                $livewire->redirect($livewire::getResource()::getUrl('view', ['record' => $kept]));
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function merge(array $data): Company|People
    {
        $current = $this->subject();
        $other = $this->entities()->whereKey($data['duplicate_id'])->first();

        if ($other === null || $current->is($other)) {
            throw ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')]);
        }

        [$kept, $duplicate] = ($data['keep'] ?? 'current') === 'other' ? [$other, $current] : [$current, $other];

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

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
        $keepsOther = $pairing !== null && $this->formState('keep') === 'other';

        $current = $this->card($this->currentRecord());
        $other = $pairing === null ? null : $this->card($pairing['other']);

        return [
            'statePath' => $this->statePath(),
            'search' => (string) $this->formState('search'),
            'results' => $this->searchResults(),
            'left' => $keepsOther ? $current : $other,
            'right' => $keepsOther ? $other : $current,
            'keepsOther' => $keepsOther,
            'result' => $pairing === null ? null : $this->resultCard($pairing),
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

        return $this->pairings["{$id}|{$keepsOther}"] ??= $this->buildPairing($id, $keepsOther);
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

    /**
     * @return array{id: mixed, name: string, logo: ?string, initials: string, details: list<string>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
     */
    private function card(Company|People $record): array
    {
        return [
            'id' => $record->getKey(),
            'name' => $record->name,
            'logo' => $record instanceof Company ? $record->logo : null,
            'initials' => $this->initials($record->name),
            'details' => $this->details($record),
            'emptyDetails' => $this->emptyDetails(),
            'counts' => $this->counts(fn (string $key): int => (int) $record->getAttribute("{$key}_count")),
        ];
    }

    /**
     * @param  array{other: Company|People, current: Company|People, values: MergedValues}  $pairing
     * @return array{id: mixed, name: string, logo: ?string, initials: string, details: list<string>, emptyDetails: string, counts: list<array{key: string, icon: string, count: int}>}
     */
    private function resultCard(array $pairing): array
    {
        $values = $pairing['values'];
        $name = (string) $values->attributes['name'];
        $kept = $this->formState('keep') === 'other' ? $pairing['other'] : $pairing['current'];
        $dropped = $kept === $pairing['other'] ? $pairing['current'] : $pairing['other'];

        return [
            'id' => null,
            'name' => $name,
            'logo' => $kept instanceof Company && $dropped instanceof Company ? $kept->logo ?? $dropped->logo : null,
            'initials' => $this->initials($name),
            'details' => $this->strings($values->customFields[$this->detailCode()] ?? []),
            'emptyDetails' => $this->emptyDetails(),
            'counts' => $this->counts(fn (string $key): int => (int) $pairing['other']->getAttribute("{$key}_count") + (int) $pairing['current']->getAttribute("{$key}_count")),
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

    private function initials(string $name): string
    {
        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
            ->implode('');
    }

    /**
     * @return list<string>
     */
    private function details(Company|People $record): array
    {
        $value = $record->customFieldValues
            ->first(fn (CustomFieldValue $fieldValue): bool => $fieldValue->customField->code === $this->detailCode())
            ?->getValue();

        return $this->strings($value);
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return array_values(Collection::wrap($value)->filter(fn (mixed $item): bool => is_string($item))->all());
    }

    /**
     * @return list<array{id: mixed, name: string, detail: ?string}>
     */
    private function searchResults(): array
    {
        $search = (string) $this->formState('search');

        $records = $this->entities()
            ->whereKeyNot($this->subject()->getKey())
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(25)
            ->get();

        return array_values($records->map(fn (Company|People $record): array => [
            'id' => $record->getKey(),
            'name' => $record->name,
            'detail' => $this->details($record)[0] ?? null,
        ])->all());
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
