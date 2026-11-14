<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Merge\CombineRecordValues;
use App\Actions\Merge\MergeCompanies;
use App\Data\MergedValues;
use App\Enums\CustomFields\CompanyField;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MergeRecordAction extends Action
{
    private const array COUNTED_RELATIONS = ['people', 'opportunities', 'notes', 'tasks'];

    /** @var array<string, array{other: Company, current: Company, values: MergedValues}|null> */
    private array $pairings = [];

    private ?Company $currentWithCounts = null;

    public static function getDefaultName(): string
    {
        return 'mergeRecord';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/record-page.actions.merge_record'))
            ->icon('lucide-merge')
            ->visible(fn (Model $record): bool => $record instanceof Company
                && Gate::allows('update', $record)
                && Gate::allows('delete', $record))
            ->modalHeading(fn (): Htmlable => new HtmlString(view('filament.app.merge-record.heading', ['name' => $this->company()->name])->render()))
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
    private function merge(array $data): Company
    {
        $current = $this->company();
        $other = Company::query()->whereKey($data['duplicate_id'])->first();

        if ($other === null || $current->is($other)) {
            throw ValidationException::withMessages(['duplicate_id' => __('filament/record-page.merge.unavailable')]);
        }

        [$kept, $duplicate] = ($data['keep'] ?? 'current') === 'other' ? [$other, $current] : [$current, $other];

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return resolve(MergeCompanies::class)->execute($user, $kept, $duplicate);
    }

    private function company(): Company
    {
        $record = $this->getRecord();
        abort_unless($record instanceof Company, 404);

        return $record;
    }

    /**
     * @return array<string, mixed>
     */
    private function dialog(): array
    {
        $pairing = $this->pairing();
        $keepsOther = $pairing !== null && $this->formState('keep') === 'other';

        $current = $this->card($this->currentCompany());
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
     * @return array{other: Company, current: Company, values: MergedValues}|null
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
     * @return array{other: Company, current: Company, values: MergedValues}|null
     */
    private function buildPairing(string $id, bool $keepsOther): ?array
    {
        $current = $this->currentCompany();
        $other = $this->companies()->whereKey($id)->whereKeyNot($current->getKey())->first();

        if ($other === null) {
            return null;
        }

        $values = resolve(CombineRecordValues::class)
            ->execute(...$keepsOther ? [$other, $current] : [$current, $other]);

        return ['other' => $other, 'current' => $current, 'values' => $values];
    }

    private function currentCompany(): Company
    {
        return $this->currentWithCounts ??= $this->companies()->whereKey($this->company()->getKey())->firstOrFail();
    }

    /**
     * @return Builder<Company>
     */
    private function companies(): Builder
    {
        return Company::query()
            ->with(['customFieldValues.customField'])
            ->withCount(self::COUNTED_RELATIONS);
    }

    /**
     * @return array{id: mixed, name: string, logo: ?string, initials: string, domains: list<string>, counts: array<string, int>}
     */
    private function card(Company $company): array
    {
        return [
            'id' => $company->getKey(),
            'name' => $company->name,
            'logo' => $company->logo,
            'initials' => $this->initials($company->name),
            'domains' => $this->domains($company),
            'counts' => Arr::mapWithKeys(self::COUNTED_RELATIONS, fn (string $relation): array => [$relation => (int) $company->getAttribute("{$relation}_count")]),
        ];
    }

    /**
     * @param  array{other: Company, current: Company, values: MergedValues}  $pairing
     * @return array{id: mixed, name: string, logo: ?string, initials: string, domains: list<string>, counts: array<string, int>}
     */
    private function resultCard(array $pairing): array
    {
        $values = $pairing['values'];
        $name = (string) $values->attributes['name'];
        $counts = Arr::mapWithKeys(self::COUNTED_RELATIONS, fn (string $relation): array => [
            $relation => (int) $pairing['other']->getAttribute("{$relation}_count") + (int) $pairing['current']->getAttribute("{$relation}_count"),
        ]);

        return [
            'id' => null,
            'name' => $name,
            'logo' => ($this->formState('keep') === 'other' ? $pairing['other'] : $pairing['current'])->logo ?? $pairing['other']->logo ?? $pairing['current']->logo,
            'initials' => $this->initials($name),
            'domains' => $this->strings($values->customFields[CompanyField::DOMAINS->value] ?? []),
            'counts' => $counts,
        ];
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
    private function domains(Company $company): array
    {
        $value = $company->customFieldValues
            ->first(fn (CustomFieldValue $fieldValue): bool => $fieldValue->customField->code === CompanyField::DOMAINS->value)
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
     * @return list<array{id: mixed, name: string, domain: ?string}>
     */
    private function searchResults(): array
    {
        $search = (string) $this->formState('search');

        $companies = $this->companies()
            ->whereKeyNot($this->company()->getKey())
            ->when($search !== '', fn (Builder $query): Builder => $query->where('name', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
            ->orderBy('name')
            ->limit(25)
            ->get();

        return array_values($companies->map(fn (Company $company): array => [
            'id' => $company->getKey(),
            'name' => $company->name,
            'domain' => $this->domains($company)[0] ?? null,
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

        $names = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->company()->workspace_id)
            ->where('entity_type', $this->company()->getMorphClass())
            ->whereIn('code', array_keys($overLimit))
            ->pluck('name', 'code');

        return array_values(Arr::map($overLimit, fn (int $max, string $code): string => __('filament/record-page.merge.over_limit', [
            'field' => $names[$code] ?? $code,
            'max' => $max,
        ])));
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
