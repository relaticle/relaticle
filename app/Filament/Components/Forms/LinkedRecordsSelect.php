<?php

declare(strict_types=1);

namespace App\Filament\Components\Forms;

use App\Actions\Note\UpdateNote;
use App\Actions\Task\UpdateTask;
use App\Enums\CrmEntity;
use App\Filament\Concerns\HasRecordChips;
use App\Models\Company;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use App\Support\LikePattern;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Renderless;
use Override;

/**
 * @phpstan-type RecordOption array{value: string, name: string, html: string, hint: ?string, url: ?string}
 * @phpstan-type RecordGroup array{label: string, options: list<RecordOption>}
 */
final class LinkedRecordsSelect extends Select
{
    use HasRecordChips;

    private const int RECENT_LIMIT = 5;

    private const int SEARCH_LIMIT = 10;

    /** @var array<int, CrmEntity> */
    private array $entities = [];

    /**
     * @param  array<int, string>  $relations
     */
    public function withoutRelations(array $relations): static
    {
        $this->entities = array_values(array_filter(
            $this->entities,
            fn (CrmEntity $entity): bool => ! in_array($entity->relationName(), $relations, true),
        ));

        return $this;
    }

    /**
     * @return list<RecordGroup>
     */
    #[ExposedLivewireMethod]
    #[Renderless]
    public function getRecordGroupsForJs(?string $search = null): array
    {
        $search = trim((string) $search);
        $groups = [];

        foreach ($this->entities as $entity) {
            $records = $this->recordQuery($entity)
                ->when($search !== '', fn (Builder $query): Builder => $query->whereLike(
                    $entity->titleColumn(),
                    '%'.LikePattern::escape($search).'%',
                ))
                ->latest('updated_at')
                ->limit($search === '' ? self::RECENT_LIMIT : self::SEARCH_LIMIT)
                ->get();

            if ($records->isNotEmpty()) {
                $groups[] = [
                    'label' => __("filament/components/linked-records.groups.{$entity->relationName()}"),
                    'options' => $this->recordOptions($entity, $records),
                ];
            }
        }

        return $groups;
    }

    /**
     * @return list<RecordOption>
     */
    public function getSelectedRecordsForJs(): array
    {
        $state = $this->getRawState();

        return is_array($state) ? array_values($this->optionsByKey($state)) : [];
    }

    #[Override]
    public function toEmbeddedHtml(): string
    {
        return $this->wrapEmbeddedHtml(
            $this->wrapInputHtml(
                view('filament.forms.components.linked-records-select', ['field' => $this])->render(),
            ),
        );
    }

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->entities = CrmEntity::linkable();

        $this
            ->label(__('filament/components/linked-records.label'))
            ->placeholder(__('filament/components/linked-records.placeholder'))
            ->multiple()
            ->dehydrated(false)
            ->getOptionLabelsUsing(static fn (self $component, array $values): array => array_map(
                fn (array $option): string => $option['name'],
                $component->optionsByKey($values),
            ))
            ->loadStateFromRelationshipsUsing(static function (self $component, ?Model $record): void {
                $component->state($record instanceof Note || $record instanceof Task ? $component->keysLinkedTo($record) : []);
            })
            ->saveRelationshipsUsing(static function (self $component, Note|Task $record, ?array $state): void {
                /** @var User $user */
                $user = auth()->user();
                $ids = $component->idsByEntity($state ?? []);

                $record instanceof Note
                    ? resolve(UpdateNote::class)->execute($user, $record, $ids)
                    : resolve(UpdateTask::class)->execute($user, $record, $ids);
            });
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<string, RecordOption>
     */
    private function optionsByKey(array $keys): array
    {
        $found = [];
        $idsByEntity = $this->idsByEntity($keys);

        foreach ($this->entities as $entity) {
            $ids = $idsByEntity["{$entity->value}_ids"];

            if ($ids === []) {
                continue;
            }

            foreach ($this->recordOptions($entity, $this->recordQuery($entity)->whereKey($ids)->get()) as $option) {
                $found[$option['value']] = $option;
            }
        }

        $ordered = [];

        foreach ($keys as $key) {
            if (is_string($key) && isset($found[$key])) {
                $ordered[$key] = $found[$key];
            }
        }

        return $ordered;
    }

    /**
     * @return array<int, string>
     */
    private function keysLinkedTo(Note|Task $record): array
    {
        $keys = [];

        foreach ($this->entities as $entity) {
            $relation = $entity->relationName();

            $ids = $record->relationLoaded($relation)
                ? $record->getRelation($relation)->modelKeys()
                : $record->{$relation}()->pluck("{$entity->table()}.id")->all();

            foreach ($ids as $id) {
                $keys[] = "{$entity->value}:{$id}";
            }
        }

        return $keys;
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<string, array<int, string>>
     */
    private function idsByEntity(array $keys): array
    {
        $ids = [];

        foreach ($this->entities as $entity) {
            $ids["{$entity->value}_ids"] = [];
        }

        foreach ($keys as $key) {
            if (! is_string($key)) {
                continue;
            }

            [$type, $id] = [...explode(':', $key, 2), ''];

            if (array_key_exists("{$type}_ids", $ids) && $id !== '') {
                $ids["{$type}_ids"][] = $id;
            }
        }

        return $ids;
    }

    /** @return Builder<Model> */
    private function recordQuery(CrmEntity $entity): Builder
    {
        $model = $entity->model();
        $query = $model::query();
        $workspace = Filament::getTenant();

        // WorkspaceScope only applies inside a panel request, so the picker names its workspace.
        if ($workspace instanceof Workspace) {
            $query->whereBelongsTo($workspace);
        }

        return $entity === CrmEntity::Company ? $query->with('media') : $query->with('company');
    }

    /**
     * @param  Collection<int, Model>  $records
     * @return list<RecordOption>
     */
    private function recordOptions(CrmEntity $entity, Collection $records): array
    {
        $urls = resolve(CanonicalRecordUrl::class);
        $workspace = Filament::getTenant();
        $options = [];

        foreach ($records as $record) {
            $company = $entity === CrmEntity::Company ? null : $record->getRelationValue('company');

            $options[] = [
                'value' => "{$entity->value}:{$record->getKey()}",
                'name' => (string) $record->getAttribute($entity->titleColumn()),
                'html' => $this->recordChipLabel($record),
                'hint' => $company instanceof Company ? $company->name : null,
                'url' => $workspace instanceof Workspace ? $urls->build($entity, (string) $record->getKey(), $workspace) : null,
            ];
        }

        return $options;
    }
}
