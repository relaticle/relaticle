<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\User;
use App\Support\ActivityLog\ActivityChangeSummary;
use App\Support\ActivityLog\ActivityValue;
use App\Support\ActivityLog\LabelledChangeLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Relaticle\Chat\Data\ProposalConflict;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Support\ProposalProgress;

final readonly class ProposalConflictsQuery
{
    public function __construct(private CustomFieldsDisplayFormatter $formatter) {}

    /**
     * Edits someone made to a record after an update proposal for it was created, on a
     * field the proposal writes. Keyed by step id, then by record index; the newest edit
     * of each field wins.
     *
     * @param  list<PendingAction>  $steps
     * @return array<string, array<int, list<ProposalConflict>>>
     */
    public function forSteps(User $user, array $steps): array
    {
        $targets = $this->targets($steps);

        if ($targets === []) {
            return [];
        }

        $activities = $this->activitiesFor($targets);
        $conflicts = [];

        foreach ($targets as $target) {
            $found = $this->conflictsOf($user, $target, $activities[$target['subjectType'].'|'.$target['recordId']] ?? []);

            if ($found !== []) {
                $conflicts[$target['stepId']][$target['index']] = $found;
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<PendingAction>  $steps
     * @return list<array{stepId: string, index: int, workspaceId: string, modelClass: class-string<Model>, subjectType: string, recordId: string, since: CarbonImmutable, codes: array<string, string>, customFields: array<string, mixed>}>
     */
    private function targets(array $steps): array
    {
        $targets = [];

        foreach ($steps as $step) {
            if ($step->operation !== PendingActionOperation::Update || ! $step->isPending()) {
                continue;
            }

            $payload = ProposalPayload::from($step);
            $indices = $payload->isBatch ? ProposalProgress::for($step)->unresolvedIndices() : [0];

            foreach ($indices as $index) {
                $record = $payload->recordAtOrEmpty($index);
                $recordId = $record['_record_id'] ?? null;
                $modelClass = $record['_model_class'] ?? null;

                if (! is_string($recordId) || ! is_string($modelClass) || ! is_a($modelClass, Model::class, true)) {
                    continue;
                }

                $targets[] = [
                    'stepId' => (string) $step->getKey(),
                    'index' => $index,
                    'workspaceId' => $step->workspace_id,
                    'modelClass' => $modelClass,
                    'subjectType' => (new $modelClass)->getMorphClass(),
                    'recordId' => $recordId,
                    'since' => $step->created_at?->toImmutable() ?? now()->toImmutable(),
                    'codes' => $this->activityCodes($record),
                    'customFields' => is_array($record['custom_fields'] ?? null) ? $record['custom_fields'] : [],
                ];
            }
        }

        return $targets;
    }

    /**
     * The activity-log code each written key is recorded under, mapped back to the key:
     * a foreign key logs as its relation (`company_id` as `company`), a link list as its
     * plural relation (`people_ids` as `people`), a custom field as its own code.
     *
     * @param  array<string, mixed>  $record
     * @return array<string, string>
     */
    private function activityCodes(array $record): array
    {
        $codes = [];

        foreach ($record as $key => $value) {
            if (str_starts_with($key, '_')) {
                continue;
            }

            if ($key === 'custom_fields') {
                foreach (array_keys(is_array($value) ? $value : []) as $code) {
                    $codes[(string) $code] = (string) $code;
                }

                continue;
            }

            $code = match (true) {
                str_ends_with($key, '_ids') => Str::plural(Str::beforeLast($key, '_ids')),
                str_ends_with($key, '_id') => Str::beforeLast($key, '_id'),
                default => $key,
            };

            $codes[$code] = $key;
        }

        return $codes;
    }

    /**
     * @param  non-empty-list<array{workspaceId: string, subjectType: string, recordId: string, since: CarbonImmutable}>  $targets
     * @return array<string, list<Activity>>
     */
    private function activitiesFor(array $targets): array
    {
        /** @var Collection<int, Activity> $activities */
        $activities = Activity::query()
            ->withoutGlobalScope(WorkspaceScope::class)
            ->whereIn('workspace_id', array_values(array_unique(array_column($targets, 'workspaceId'))))
            ->whereIn('subject_type', array_values(array_unique(array_column($targets, 'subjectType'))))
            ->whereIn('subject_id', array_values(array_unique(array_column($targets, 'recordId'))))
            ->whereIn('event', ['updated', LabelledChangeLog::EVENT])
            ->where('created_at', '>=', min(array_column($targets, 'since')))
            ->inAuditTrail()
            ->with('causer')
            ->oldest('created_at')
            ->oldest('id')
            ->get();

        $bySubject = [];

        foreach ($activities as $activity) {
            $bySubject[$activity->subject_type.'|'.$activity->subject_id][] = $activity;
        }

        return $bySubject;
    }

    /**
     * Proposal time and edit time are both stored to the second, so an edit in the same
     * second as the proposal counts: a missed warning costs more than a spare one.
     *
     * @param  array{modelClass: class-string<Model>, recordId: string, workspaceId: string, since: CarbonImmutable, codes: array<string, string>, customFields: array<string, mixed>}  $target
     * @param  list<Activity>  $activities
     * @return list<ProposalConflict>
     */
    private function conflictsOf(User $user, array $target, array $activities): array
    {
        $latest = [];

        foreach ($activities as $activity) {
            $at = $activity->created_at?->toImmutable();

            if ($at === null || $at->lt($target['since'])) {
                continue;
            }

            foreach (ActivityChangeSummary::for($activity) as $row) {
                $key = $target['codes'][$row['code']] ?? null;

                if ($key === null) {
                    continue;
                }

                $latest[$key] = new ProposalConflict(
                    activityId: (string) $activity->getKey(),
                    key: $key,
                    field: $row['label'],
                    value: $row['new'] === ActivityValue::EMPTY ? null : $row['new'],
                    causerId: $activity->causer_id === null ? null : (string) $activity->causer_id,
                    causerName: $activity->causerLabel(),
                    at: $at,
                );
            }
        }

        if ($latest === []) {
            return [];
        }

        return array_values($this->withCurrentCustomFieldValues($user, $target, $latest));
    }

    /**
     * A custom field's value reads as the card renders it ("$20,000"), not as the log
     * stored it ("20000"): the warning and the line beside it must agree.
     *
     * @param  array{modelClass: class-string<Model>, recordId: string, workspaceId: string, customFields: array<string, mixed>}  $target
     * @param  array<string, ProposalConflict>  $conflicts
     * @return array<string, ProposalConflict>
     */
    private function withCurrentCustomFieldValues(User $user, array $target, array $conflicts): array
    {
        $customFields = array_intersect_key($target['customFields'], $conflicts);

        if ($customFields === []) {
            return $conflicts;
        }

        $record = $target['modelClass']::query()
            ->withoutGlobalScopes()
            ->where('workspace_id', $target['workspaceId'])
            ->whereKey($target['recordId'])
            ->first();

        if (! $record instanceof Model) {
            return $conflicts;
        }

        foreach ($this->formatter->format($user, $record->getMorphClass(), $customFields, oldModel: $record) as $row) {
            $conflict = $conflicts[$row['code']] ?? null;

            if (! $conflict instanceof ProposalConflict) {
                continue;
            }

            $conflicts[$row['code']] = new ProposalConflict(
                activityId: $conflict->activityId,
                key: $conflict->key,
                field: $row['label'],
                value: in_array($row['old'] ?? null, [null, ''], true) ? null : $row['old'],
                causerId: $conflict->causerId,
                causerName: $conflict->causerName,
                at: $conflict->at,
            );
        }

        return $conflicts;
    }
}
