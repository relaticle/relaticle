<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\User;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\Tools\ProposalFieldSchemaDescriber;
use Relaticle\CustomFields\Services\TenantContextService;
use RuntimeException;

/**
 * Orchestrates editing a chat create-proposal before approval. Task 3 exposes
 * the structured editable schema (single, or one item of a batch); the PATCH
 * edit path (re-validate + re-render display without execution) lands in Task 4.
 */
final readonly class ProposalEditor
{
    public function __construct(
        private ProposalFieldSchemaDescriber $describer,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function editableSchema(PendingAction $pendingAction, User $user, ?int $index = null): array
    {
        $this->assertEditable($pendingAction);

        $record = $this->resolveRecord($pendingAction, $index);

        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($pendingAction->team_id);

        try {
            return $this->describer->describe($user, $pendingAction->entity_type, $record);
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRecord(PendingAction $pendingAction, ?int $index): array
    {
        if (($pendingAction->action_data['_batch'] ?? false) !== true) {
            return $pendingAction->action_data;
        }

        throw_if($index === null, RuntimeException::class, 'A batch item index is required');

        $records = $pendingAction->action_data['records'] ?? null;

        throw_if(! is_array($records) || $records === [], RuntimeException::class, 'Missing or invalid records in batch action data');

        $records = array_values($records);

        throw_if($index < 0 || $index >= count($records), RuntimeException::class, 'Item index out of range');

        $record = $records[$index];

        throw_if(! is_array($record), RuntimeException::class, 'Batch record data is malformed');

        return $record;
    }

    private function assertEditable(PendingAction $pendingAction): void
    {
        throw_if(
            $pendingAction->operation !== PendingActionOperation::Create,
            RuntimeException::class,
            'Only pending create proposals can be edited',
        );

        if ($pendingAction->isPending() && $pendingAction->isExpired()) {
            $pendingAction->update([
                'status' => PendingActionStatus::Expired,
                'resolved_at' => now(),
            ]);
            throw new RuntimeException('This action has expired');
        }

        throw_unless($pendingAction->isPending(), RuntimeException::class, 'This action has already been resolved');
    }
}
