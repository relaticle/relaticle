<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Activity;

use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\ActivityLog\Support\ActivityLogDiffRow;
use Relaticle\ActivityLog\Support\AttributeFormatter;
use Relaticle\Chat\Support\RecordReferenceResolver;

/**
 * "What changed on this deal last week", answered from the activity log.
 *
 * @phpstan-type ChangeRow array{field: string, old: string|null, new: string|null}
 * @phpstan-type ActivityEntry array{at: string, by: string, event: string, record: array{type: string, id: string, name: string, url: string}, changes: list<ChangeRow>}
 */
final readonly class ListActivityTool implements Tool
{
    /**
     * Activity rows fetched for the model-facing payload. Fifty edits is already
     * more history than a chat answer can use, and the payload is replayed on
     * every later turn of the conversation. Rows whose subject no longer exists
     * are excluded in SQL, so a purged record never consumes this budget; the
     * merged entries the payload carries are therefore at most this many, and
     * usually fewer, with `total` reporting the true figure for the window.
     */
    private const int ENTRY_LIMIT = 50;

    /**
     * Rows carried by the `records_table` display block, matching the read list
     * tools: a chat bubble that scrolls past ten rows stops being a summary.
     */
    private const int BLOCK_ROW_LIMIT = 10;

    /**
     * Characters kept in the "what changed" cell, matching the cell cap the
     * read list tools use.
     */
    private const int CELL_VALUE_LIMIT = 120;

    private const int DEFAULT_DAYS = 7;

    private const int MAX_DAYS = 30;

    public function __construct(private RecordReferenceResolver $references) {}

    public function description(): string
    {
        return 'Read the change history of CRM records: who changed what, and when.'
            .' Use it for questions like "what changed on this deal last week", "what did'
            .' my team update recently", or "who edited this company".'
            .' Scope it to one record with record_type + record_id, to one entity type with'
            .' record_type alone, or leave both out for the whole workspace.'
            .' This is history, not search: to list or filter records themselves, use the list tools.';
    }

    public function schema(JsonSchema $schema): array
    {
        $types = implode(', ', RecordReferenceResolver::CHIP_TYPES);

        return [
            'record_type' => $schema->string()->description("Limit to one entity type. One of: {$types}."),
            'record_id' => $schema->string()->description('Limit to a single record. Requires record_type.'),
            'days' => $schema->integer()
                ->description('How many days back to look (default '.self::DEFAULT_DAYS.', max '.self::MAX_DAYS.').')
                ->default(self::DEFAULT_DAYS),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $recordType = $this->stringOrNull($request['record_type'] ?? null);
        $recordId = $this->stringOrNull($request['record_id'] ?? null);

        if ($recordType !== null && ! in_array($recordType, RecordReferenceResolver::CHIP_TYPES, true)) {
            return $this->error("Unknown record_type [{$recordType}]. Valid values: ".implode(', ', RecordReferenceResolver::CHIP_TYPES).'.');
        }

        if ($recordId !== null && $recordType === null) {
            return $this->error('record_id requires record_type so the record can be identified.');
        }

        if ($recordType !== null && $recordId !== null) {
            $error = $this->assertRecordVisible($user, $recordType, $recordId);

            if ($error !== null) {
                return $error;
            }
        }

        $days = $this->days($request);
        $scope = $this->scopedQuery((string) $team->getKey(), $days, $recordType, $recordId);

        $rows = $scope->clone()
            ->with(['causer', 'subject'])
            // `created_at` is second-precision, so several rows of one save tie
            // on it; the auto-increment id breaks the tie deterministically.
            ->latest()
            ->orderByDesc('id')
            ->limit(self::ENTRY_LIMIT)
            ->get();

        $entries = [];

        foreach ($this->groupBySave($rows) as $group) {
            $entry = $this->entry($user, $group);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        $payload = ['days' => $days, 'data' => $entries];

        if ($entries !== []) {
            $payload['display_block'] = $this->buildDisplayBlock($entries, $this->countEntries($scope));
        }

        return (string) json_encode($payload, JSON_PRETTY_PRINT);
    }

    /**
     * Every activity row the caller may read in the window, unordered and
     * uncapped: the fetch and the count both start from this, so the footer can
     * never disagree with the rows about which history exists.
     *
     * @return Builder<Activity>
     */
    private function scopedQuery(string $teamId, int $days, ?string $recordType, ?string $recordId): Builder
    {
        $query = Activity::query()
            // The agent runs in a queued job with no Filament tenant bound, and
            // TeamScope answers a null tenant with `1 = 0` -- every row would
            // vanish in production while passing in a panel request. The team
            // predicate below is the real boundary, stated explicitly.
            ->withoutGlobalScope(TeamScope::class)
            ->where('team_id', $teamId)
            ->where('created_at', '>=', now()->subDays($days))
            // Nothing cascades activity rows, so a force-deleted record leaves
            // history that can be neither named nor opened. Dropped HERE rather
            // than after the fetch: filtered in PHP, a workspace whose newest
            // rows all point at purged records would spend the whole row budget
            // on them and answer "nothing changed" while real history waited
            // outside the limit. The soft-delete scope is lifted so a genuine
            // deletion keeps naming its record, matching the `subject` relation
            // (`activitylog.include_soft_deleted_subjects`).
            ->whereHasMorph(
                'subject',
                $recordType !== null ? [$recordType] : RecordReferenceResolver::CHIP_TYPES,
                static fn (Builder $subject): Builder => $subject->withoutGlobalScope(SoftDeletingScope::class),
            );

        if ($recordId !== null) {
            $query->where('subject_id', $recordId);
        }

        return $query;
    }

    /**
     * How many entries the window really holds, counted the way groupBySave()
     * groups them. `total` feeds a footer shared with every other display block,
     * and the model reads it too, so it has to mean the same thing everywhere: a
     * count capped at ENTRY_LIMIT renders identically to a true one and would
     * quietly tell a user that ten of forty-three records changed last month
     * when hundreds did.
     *
     * @param  Builder<Activity>  $scope
     */
    private function countEntries(Builder $scope): int
    {
        return (int) $scope->clone()
            ->toBase()
            ->selectRaw("count(distinct (coalesce(batch_uuid::text, 'row:' || id::text), subject_type, subject_id)) as aggregate")
            ->value('aggregate');
    }

    /**
     * Rows written by one save, collapsed into one entry. A save that touches a
     * native column and a custom field writes one row for each, sharing a
     * `batch_uuid` -- the same grouping the record timeline does, so chat and
     * the panel never disagree about how many times a record was edited.
     *
     * The subject is part of the key, unlike in the timeline: `RequestActivityBatch`
     * holds one uuid for a whole request or queued job, so a job that saved
     * several records stamped them all alike, and batch alone would merge
     * unrelated records here.
     *
     * @param  iterable<int, Activity>  $activities
     * @return list<list<Activity>>
     */
    private function groupBySave(iterable $activities): array
    {
        $groups = [];

        foreach ($activities as $activity) {
            $batch = $activity->batch_uuid;

            $key = ($batch === null || $batch === '')
                ? 'row:'.$activity->getKey()
                : "batch:{$batch}:{$activity->subject_type}:{$activity->subject_id}";

            $groups[$key][] = $activity;
        }

        return array_values($groups);
    }

    /**
     * Null when the record itself is gone for good. scopedQuery() already drops
     * those rows in SQL, so this is the belt to that braces: a subject the
     * database says exists but the relation cannot hydrate must not reach the
     * table as a nameless, unopenable row.
     *
     * @param  list<Activity>  $group
     * @return ActivityEntry|null
     */
    private function entry(User $user, array $group): ?array
    {
        $base = $this->baseRow($group);
        $subject = $base->subject;

        if (! $subject instanceof Model) {
            return null;
        }

        $properties = $this->mergedProperties($group);
        $changes = $this->changes($properties);
        $event = (string) ($base->event ?? $base->description);

        $subjectType = (string) $base->subject_type;
        $subjectId = (string) $base->subject_id;

        return [
            'at' => $this->occurredAt($user, $base)->toIso8601String(),
            'by' => $this->causerName($base),
            'event' => $event,
            'record' => [
                'type' => $subjectType,
                'id' => $subjectId,
                'name' => $this->recordName($subject),
                'url' => $this->references->referenceUrl($subjectType, $subjectId),
            ],
            'changes' => $changes,
        ];
    }

    /**
     * The row that names the save. A custom-field row logs the event
     * `custom_field_changes`, so a save that also touched a native column is
     * better described by that column's row.
     *
     * @param  list<Activity>  $group
     */
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
     * Both payload columns of every row in the save, in one array.
     *
     * Spatie v5 splits a change across two columns: trait-logged native diffs
     * land in `attribute_changes`, manual `withProperties()` calls (custom-field
     * edits) in `properties`. Reading only one is the known cause of an empty
     * "no field changes" summary. Both are cast to a Collection, and a
     * Collection stringifies to its own JSON, so every read goes through
     * `toArray()` and no value is ever cast to string on the way out.
     *
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
                // Repeated array-valued keys accumulate rather than overwrite: a
                // save touching several custom fields emits one row each, all
                // under `custom_field_changes`.
                $merged[$key] = isset($merged[$key]) && is_array($merged[$key]) && is_array($value)
                    ? array_merge($merged[$key], $value)
                    : $value;
            }
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return list<ChangeRow>
     */
    private function changes(array $properties): array
    {
        $rows = [];

        $new = is_array($properties['attributes'] ?? null) ? $properties['attributes'] : [];
        $old = is_array($properties['old'] ?? null) ? $properties['old'] : [];

        /** @var list<string> $keys */
        $keys = array_values(array_unique([...array_keys($new), ...array_keys($old)]));

        foreach ($keys as $key) {
            if (! $this->isPublicKey($key)) {
                continue;
            }

            // Native values are rendered by the package formatter, so a chat
            // table and the record timeline print the same value for the same
            // change (see ActivityLogSummary, which builds these rows for the
            // panel).
            $row = new ActivityLogDiffRow(
                label: Str::headline($key),
                old: $old[$key] ?? null,
                new: $new[$key] ?? null,
            );

            $rows[] = [
                'field' => $row->label,
                // Null rather than the formatter's placeholder glyph: the model
                // reads this payload, and "unset" is a fact, not a dash.
                'old' => $this->isUnset($row->old) ? null : $row->formattedOld(),
                'new' => $this->isUnset($row->new) ? null : $row->formattedNew(),
            ];
        }

        $customFieldChanges = is_array($properties['custom_field_changes'] ?? null)
            ? $properties['custom_field_changes']
            : [];

        foreach ($customFieldChanges as $change) {
            if (! is_array($change)) {
                continue;
            }

            $label = $change['label'] ?? $change['code'] ?? null;

            $rows[] = [
                'field' => is_string($label) ? $label : '',
                // The writer already rendered both sides into a human label
                // (option names, Yes/No, formatted dates) when it logged the
                // change -- CustomFieldValueObserver::describe(). Re-deriving
                // them here would be a second, divergent formatter.
                'old' => $this->customFieldSide($change['old'] ?? null),
                'new' => $this->customFieldSide($change['new'] ?? null),
            ];
        }

        return $rows;
    }

    /**
     * Keys the record timeline hides, mirroring ActivityLogSummary::isPublicKey():
     * internal markers and the timestamps every save touches.
     */
    private function isPublicKey(string $key): bool
    {
        if (str_starts_with($key, '_')) {
            return false;
        }

        return ! in_array($key, ['created_at', 'updated_at', 'deleted_at'], true);
    }

    private function isUnset(mixed $value): bool
    {
        return $value === null || $value === '';
    }

    /**
     * One side of a custom-field change, as `CustomFieldValueObserver` wrote it:
     * `['value' => mixed, 'label' => string]`, with a null value standing for a
     * field that held nothing.
     */
    private function customFieldSide(mixed $side): ?string
    {
        if (! is_array($side) || ($side['value'] ?? null) === null) {
            return null;
        }

        $label = $side['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * The presentation envelope the chat UI renders as a table. Its chrome is
     * generated in English and translated at render time against the reader's
     * locale (see chat-interface.blade.php): this runs inside a queued job whose
     * locale belongs to whoever sent the turn, not to whoever reads it later.
     *
     * @param  list<ActivityEntry>  $entries
     * @return array<string, mixed>
     */
    private function buildDisplayBlock(array $entries, int $total): array
    {
        $rows = [];

        foreach (array_slice($entries, 0, self::BLOCK_ROW_LIMIT) as $index => $entry) {
            $rows[] = [
                'id' => (string) $index,
                // Per-row, unlike a records_table: the subjects of one activity
                // table are a mix of companies, tasks and notes, and each row's
                // chip carries its own icon.
                'type' => $entry['record']['type'],
                'url' => $entry['record']['url'],
                'cells' => [
                    'when' => $this->whenCell($entry['at']),
                    'record' => $entry['record']['name'],
                    'who' => $entry['by'],
                    'what' => $this->whatCell($entry),
                ],
            ];
        }

        return [
            'block' => 'records_table',
            'title' => 'Activity',
            'type' => 'activity',
            'core' => 'record',
            'columns' => [
                ['key' => 'when', 'label' => 'When'],
                ['key' => 'record', 'label' => 'Record'],
                ['key' => 'who', 'label' => 'Who'],
                ['key' => 'what', 'label' => 'What Changed'],
            ],
            'rows' => $rows,
            'total' => $total,
        ];
    }

    private function whenCell(string $at): string
    {
        return Date::parse($at)->isoFormat('MMM D, HH:mm');
    }

    /**
     * @param  ActivityEntry  $entry
     */
    private function whatCell(array $entry): string
    {
        if ($entry['changes'] === []) {
            return Str::headline($entry['event']);
        }

        return match ($entry['event']) {
            // A creation logs every initial value and a deletion logs none, so
            // neither reads as a diff. The record column already names them.
            'created' => 'Created',
            'deleted' => 'Deleted',
            default => Str::limit(implode('; ', array_map(
                static fn (array $change): string => sprintf(
                    '%s: %s → %s',
                    $change['field'],
                    AttributeFormatter::format($change['old']),
                    AttributeFormatter::format($change['new']),
                ),
                $entry['changes'],
            )), self::CELL_VALUE_LIMIT),
        };
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

    private function recordName(Model $subject): string
    {
        foreach (['name', 'title'] as $attribute) {
            $value = $subject->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * The record must belong to the caller's team before its history is read;
     * a stranger's id must not even reveal that the record exists.
     */
    private function assertRecordVisible(User $user, string $recordType, string $recordId): ?string
    {
        /** @var class-string<Model>|null $modelClass */
        $modelClass = Relation::getMorphedModel($recordType);

        if ($modelClass === null) {
            return $this->error("Unknown record_type [{$recordType}].");
        }

        $record = $modelClass::query()
            ->whereBelongsTo($user->currentTeam)
            ->whereKey($recordId)
            ->first();

        if (! $record instanceof Model || $user->cannot('view', $record)) {
            return $this->error(Str::headline($recordType)." with ID [{$recordId}] not found.");
        }

        return null;
    }

    private function days(Request $request): int
    {
        $days = $request['days'] ?? null;

        if (! is_numeric($days)) {
            return self::DEFAULT_DAYS;
        }

        return max(1, min((int) $days, self::MAX_DAYS));
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function error(string $message): string
    {
        return (string) json_encode(['error' => $message]);
    }
}
