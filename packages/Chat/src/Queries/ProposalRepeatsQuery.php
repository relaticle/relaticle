<?php

declare(strict_types=1);

namespace Relaticle\Chat\Queries;

use App\Enums\CrmEntity;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Support\RecordReferenceResolver;

final readonly class ProposalRepeatsQuery
{
    public function __construct(private RecordReferenceResolver $references) {}

    /**
     * @param  list<PendingAction>  $steps
     * @return array<string, array<int, array{name: string, url: string}>>
     */
    public function forSteps(User $user, array $steps): array
    {
        $creates = array_values(array_filter(
            $steps,
            fn (PendingAction $step): bool => CrmEntity::tryFrom($step->entity_type->value) instanceof CrmEntity && $this->isOwnCreate($user, $step),
        ));

        if ($creates === []) {
            return [];
        }

        $existing = $this->existingRecordsByName($user, $creates);
        $repeats = [];

        foreach ($creates as $step) {
            $entity = CrmEntity::from($step->entity_type->value);
            $payload = ProposalPayload::from($step);

            for ($index = 0; $index < $payload->count(); $index++) {
                $name = $this->normalise($payload->recordAtOrEmpty($index)[$entity->titleColumn()] ?? null);
                $match = $existing[$step->conversation_id.'|'.$entity->value][$name] ?? null;

                if ($name !== '' && $match !== null) {
                    $repeats[(string) $step->getKey()][$index] = $match;
                }
            }
        }

        return $repeats;
    }

    private function isOwnCreate(User $user, PendingAction $step): bool
    {
        return $step->operation === PendingActionOperation::Create
            && $step->conversation_id !== null
            && (string) $step->user_id === (string) $user->getKey()
            && (string) $step->workspace_id === (string) $user->current_workspace_id;
    }

    /**
     * @param  non-empty-list<PendingAction>  $creates
     * @return array<string, array<string, array{name: string, url: string}>>
     */
    private function existingRecordsByName(User $user, array $creates): array
    {
        $approved = PendingAction::query()
            ->whereIn('conversation_id', array_unique(array_map(static fn (PendingAction $step): string => (string) $step->conversation_id, $creates)))
            ->where('user_id', $user->getKey())
            ->where('workspace_id', $user->current_workspace_id)
            ->where('operation', PendingActionOperation::Create)
            ->where('status', PendingActionStatus::Approved)
            ->get(['id', 'conversation_id', 'entity_type', 'result_data']);

        $idsByEntity = [];

        foreach ($approved as $action) {
            foreach ($action->createdRecordIds() as $id) {
                $idsByEntity[$action->entity_type->value][$id] = (string) $action->conversation_id;
            }
        }

        $existing = [];

        foreach ($idsByEntity as $type => $conversationOf) {
            $entity = CrmEntity::tryFrom($type);

            if (! $entity instanceof CrmEntity) {
                continue;
            }

            $column = $entity->titleColumn();

            $records = $entity->model()::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $user->current_workspace_id)
                ->whereKey(array_keys($conversationOf))
                ->get(['id', $column]);

            foreach ($records as $record) {
                $name = $record->getAttribute($column);

                if ($this->normalise($name) !== '') {
                    $existing[$conversationOf[(string) $record->getKey()].'|'.$type][$this->normalise($name)] = [
                        'name' => (string) $name,
                        'url' => $this->references->referenceUrl($type, (string) $record->getKey()),
                    ];
                }
            }
        }

        return $existing;
    }

    private function normalise(mixed $name): string
    {
        return is_string($name) ? Str::lower(Str::squish($name)) : '';
    }
}
