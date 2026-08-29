<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\CrmEntity;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\User;
use App\Support\CanonicalRecordUrl;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\ActivityLog\Support\ActivityLogDiffRow;

#[Title('List CRM Activity')]
#[Description('List who changed which CRM records, when they changed them, and the field-level differences. Results use the caller timezone.')]
final class ListActivityTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    private const int ENTRY_LIMIT = 25;

    private const int MAX_DAYS = 30;

    private const int MAX_PAGE = 1_000_000;

    private const string SAVE_KEY_SQL = "coalesce(batch_uuid::text, 'row:' || id::text)";

    public function __construct(
        private readonly CanonicalRecordUrl $urls,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'record_type' => $schema->string()->description('Optional entity type: company, people, opportunity, task, or note.'),
            'record_id' => $schema->string()->description('Optional record ID. Requires record_type.'),
            'days' => $schema->integer()->description('Days of history to include (default 7, max 30).')->default(7),
            'page' => $schema->integer()->description('Page number (default 1, max 1,000,000, 25 complete saves per page).')->default(1),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object())->required(),
            'days' => $schema->integer()->required(),
            'page' => $schema->integer()->required(),
            'total' => $schema->integer()->required(),
            'has_more' => $schema->boolean()->required(),
            'next_page' => $schema->integer()->nullable()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        $validated = $request->validate([
            'record_type' => ['string', 'required_with:record_id', Rule::in(CrmEntity::morphAliases())],
            'record_id' => ['sometimes', 'string'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);

        /** @var User $user */
        $user = $request->user();
        $recordType = isset($validated['record_type']) ? CrmEntity::from((string) $validated['record_type']) : null;
        $recordId = isset($validated['record_id']) ? (string) $validated['record_id'] : null;
        $days = (int) ($validated['days'] ?? 7);
        $page = (int) ($validated['page'] ?? 1);

        $entities = $recordType instanceof CrmEntity ? [$recordType] : CrmEntity::cases();

        foreach ($entities as $entity) {
            if ($user->cannot('viewAny', $entity->model())) {
                return Response::error('You do not have permission to view CRM activity.');
            }
        }

        if ($recordId !== null && $recordType instanceof CrmEntity) {
            $record = $this->visibleRecord($user, $recordType, $recordId);

            if (! $record instanceof Model) {
                return Response::error("Record [{$recordId}] was not found or is not visible.");
            }
        }

        $scope = $this->scopedQuery((string) $user->currentTeam->getKey(), $days, $recordType, $recordId);
        $keys = $this->saveKeysForPage($scope, $page);
        $hasMore = count($keys) > self::ENTRY_LIMIT;
        $keys = array_slice($keys, 0, self::ENTRY_LIMIT);
        $rows = $keys === [] ? [] : $this->rowsForSaves($scope, $keys);
        $entries = [];

        foreach ($this->groupBySave($rows) as $group) {
            $entry = $this->entry($user, $group);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        return Response::structured([
            'items' => $entries,
            'days' => $days,
            'page' => $page,
            'total' => $this->countEntries($scope),
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
        ]);
    }

    /** @return Builder<Activity> */
    private function scopedQuery(string $teamId, int $days, ?CrmEntity $recordType, ?string $recordId): Builder
    {
        $query = Activity::query()
            ->withoutGlobalScope(TeamScope::class)
            ->where('team_id', $teamId)
            ->where('created_at', '>=', now()->subDays($days))
            ->whereHasMorph(
                'subject',
                $recordType instanceof CrmEntity ? [$recordType->value] : CrmEntity::morphAliases(),
                static fn (Builder $subject): Builder => $subject->withoutGlobalScope(SoftDeletingScope::class),
            );

        if ($recordId !== null) {
            $query->where('subject_id', $recordId);
        }

        return $query;
    }

    /** @param Builder<Activity> $scope */
    private function countEntries(Builder $scope): int
    {
        return (int) $scope->clone()
            ->toBase()
            ->selectRaw('count(distinct ('.self::SAVE_KEY_SQL.', subject_type, subject_id)) as aggregate')
            ->value('aggregate');
    }

    /**
     * @param  Builder<Activity>  $scope
     * @return list<array{grp: string, subject_type: string, subject_id: string}>
     */
    private function saveKeysForPage(Builder $scope, int $page): array
    {
        $rows = $scope->clone()
            ->toBase()
            ->selectRaw(self::SAVE_KEY_SQL.' as grp')
            ->addSelect('subject_type', 'subject_id')
            ->selectRaw('max(created_at) as last_at, max(id) as last_id')
            ->groupByRaw(self::SAVE_KEY_SQL.', subject_type, subject_id')
            ->latest('last_at')
            ->orderByDesc('last_id')
            ->offset(($page - 1) * self::ENTRY_LIMIT)
            ->limit(self::ENTRY_LIMIT + 1)
            ->get();

        return array_values(array_map(static fn (object $row): array => [
            'grp' => (string) $row->grp,
            'subject_type' => (string) $row->subject_type,
            'subject_id' => (string) $row->subject_id,
        ], $rows->all()));
    }

    /**
     * @param  Builder<Activity>  $scope
     * @param  list<array{grp: string, subject_type: string, subject_id: string}>  $keys
     * @return list<Activity>
     */
    private function rowsForSaves(Builder $scope, array $keys): array
    {
        $bindings = [];

        foreach ($keys as $key) {
            $bindings[] = $key['grp'];
            $bindings[] = $key['subject_type'];
            $bindings[] = $key['subject_id'];
        }

        $tupleSql = implode(', ', array_fill(0, count($keys), '(?, ?, ?)'));

        return array_values($scope->clone()
            ->whereRaw('('.self::SAVE_KEY_SQL.", subject_type, subject_id) in ({$tupleSql})", $bindings)
            ->with(['subject' => fn (Relation $relation): Relation => $relation->withoutGlobalScope(SoftDeletingScope::class), 'causer'])
            ->latest('created_at')
            ->orderByDesc('id')
            ->get()
            ->all());
    }

    /**
     * @param  iterable<int, Activity>  $activities
     * @return list<list<Activity>>
     */
    private function groupBySave(iterable $activities): array
    {
        $groups = [];

        foreach ($activities as $activity) {
            $batch = $activity->batch_uuid;
            $key = $batch === null
                ? 'row:'.$activity->getKey()
                : "batch:{$batch}:{$activity->subject_type}:{$activity->subject_id}";
            $groups[$key][] = $activity;
        }

        return array_values($groups);
    }

    /**
     * @param  list<Activity>  $group
     * @return array<string, mixed>|null
     */
    private function entry(User $user, array $group): ?array
    {
        $base = $this->baseRow($group);
        $subject = $base->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        $subjectType = (string) $base->subject_type;
        $subjectId = (string) $base->subject_id;
        $entity = CrmEntity::tryFrom($subjectType);

        return [
            'at' => $this->occurredAt($user, $base)->toIso8601String(),
            'by' => $this->causerName($base),
            'event' => (string) ($base->event ?? $base->description),
            'record' => [
                'type' => $subjectType,
                'id' => $subjectId,
                'name' => $this->recordName($subject, $entity),
                'url' => $entity instanceof CrmEntity
                    ? $this->urls->build($entity, $subjectId, $user->currentTeam)
                    : null,
            ],
            'changes' => $this->changes($this->mergedProperties($group)),
        ];
    }

    /** @param list<Activity> $group */
    private function baseRow(array $group): Activity
    {
        foreach ($group as $activity) {
            if (($activity->attribute_changes?->toArray() ?? []) !== []) {
                return $activity;
            }
        }

        return $group[0];
    }

    /**
     * @param  list<Activity>  $group
     * @return array<string, mixed>
     */
    private function mergedProperties(array $group): array
    {
        $merged = [];

        foreach ($group as $activity) {
            $row = [
                ...($activity->properties?->toArray() ?? []),
                ...($activity->attribute_changes?->toArray() ?? []),
            ];

            foreach ($row as $key => $value) {
                $merged[$key] = isset($merged[$key]) && is_array($merged[$key]) && is_array($value)
                    ? array_merge($merged[$key], $value)
                    : $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<array{field: string, old: string|null, new: string|null}>
     */
    private function changes(array $properties): array
    {
        $rows = [];
        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];
        $keys = array_values(array_unique([...array_keys($new), ...array_keys($old)]));

        foreach ($keys as $key) {
            if (str_starts_with($key, '_') || in_array($key, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $row = new ActivityLogDiffRow(
                label: Str::headline($key),
                old: $old[$key] ?? null,
                new: $new[$key] ?? null,
            );

            $rows[] = [
                'field' => $row->label,
                'old' => $this->isUnset($row->old) ? null : $row->formattedOld(),
                'new' => $this->isUnset($row->new) ? null : $row->formattedNew(),
            ];
        }

        $customChanges = is_array($properties['custom_field_changes'] ?? null)
            ? $properties['custom_field_changes']
            : [];

        foreach ($customChanges as $change) {
            if (! is_array($change)) {
                continue;
            }

            $label = $change['label'] ?? $change['code'] ?? '';
            $rows[] = [
                'field' => is_string($label) ? $label : '',
                'old' => $this->customFieldSide($change['old'] ?? null),
                'new' => $this->customFieldSide($change['new'] ?? null),
            ];
        }

        return $rows;
    }

    private function isUnset(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    private function customFieldSide(mixed $side): ?string
    {
        if (! is_array($side) || ($side['value'] ?? null) === null) {
            return null;
        }

        $label = $side['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    private function occurredAt(User $user, Activity $activity): Carbon
    {
        return Date::parse($activity->created_at)->setTimezone($user->effectiveTimezone());
    }

    private function causerName(Activity $activity): string
    {
        $name = $activity->causer?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : 'System';
    }

    private function recordName(Model $subject, ?CrmEntity $entity): string
    {
        if (! $entity instanceof CrmEntity) {
            return '';
        }

        $value = $subject->getAttribute($entity->titleColumn());

        return is_string($value) && $value !== '' ? $value : '';
    }

    private function visibleRecord(User $user, CrmEntity $recordType, string $recordId): ?Model
    {
        $modelClass = $recordType->model();
        $model = $modelClass::query()->find($recordId);

        return $model instanceof Model && $user->can('view', $model) ? $model : null;
    }
}
