<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\ProposalDisplayBuilder;
use Relaticle\Chat\Support\ProposalOwnership;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Support\WorkspaceMembersContext;
use RuntimeException;

/**
 * Orchestrates editing a chat create-proposal before approval: re-validate the
 * edited core + custom fields, rewrite the clean action_data, and re-render
 * display_data, never executing the action and never dispatching a
 * continuation. Driven by the docked ProposalCard's saveField flow.
 */
final readonly class ProposalEditor
{
    public function __construct(
        private ProposalDisplayBuilder $displayBuilder,
        private CustomFieldsRequestValidator $customFieldsValidator,
    ) {}

    /**
     * Re-validate the edited fields, rewrite action_data, and re-render
     * display_data for a single create-proposal (or one batch item). Returns
     * the refreshed PendingAction; it stays Pending, and the action is never run.
     *
     * @param  array<string, mixed>  $input  the edited fields keyed by code
     */
    public function applyEdit(PendingAction $pendingAction, User $user, array $input, ?int $index = null): PendingAction
    {
        // Before the pin below, not after: this method validates core fields
        // against the actor's workspace while writing custom fields under the
        // proposal's, so a cross-tenant caller would split one record in two.
        $workspace = ProposalOwnership::assert($pendingAction, $user);

        return resolve(CurrentWorkspace::class)->within($workspace, function () use ($pendingAction, $user, $input, $index): PendingAction {
            return DB::transaction(function () use ($pendingAction, $user, $input, $index): PendingAction {
                /** @var PendingAction $locked */
                $locked = PendingAction::query()->lockForUpdate()->findOrFail($pendingAction->getKey());

                $this->assertEditable($locked);

                $record = $this->resolveRecord($locked, $index);
                $entity = $locked->entity_type;

                [$editedCore, $editedCustomFields] = $this->splitInput($entity, $input);

                $this->validateCore($user, $entity, $editedCore);

                $cleanFields = $this->validateCustomFields($user, $entity, $editedCustomFields);

                $rebuiltRecord = $this->rebuildRecord($user, $entity, $record, $editedCore, $editedCustomFields, $cleanFields);

                $rebuiltDisplay = $this->displayBuilder->build(
                    $user,
                    $entity,
                    $rebuiltRecord,
                    $this->currentDisplayFields($locked, $index),
                );

                $this->persist($locked, $index, $rebuiltRecord, $rebuiltDisplay);

                return $locked->refresh();
            });
        });
    }

    /**
     * Split the edited fields into core (title/name + company account_owner_id)
     * and custom (everything else, keyed by custom-field code).
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitInput(ProposalEntity $entity, array $input): array
    {
        $core = [];
        $custom = [];

        foreach ($input as $code => $value) {
            if ($entity->isCore($code)) {
                $core[$code] = $value;

                continue;
            }

            $custom[$code] = $value;
        }

        return [$core, $custom];
    }

    /**
     * @param  array<string, mixed>  $editedCore
     */
    private function validateCore(User $user, ProposalEntity $entity, array $editedCore): void
    {
        $titleKey = $entity->titleKey();

        if (array_key_exists($titleKey, $editedCore)) {
            $value = trim((string) $editedCore[$titleKey]);
            $label = $titleKey === 'title' ? 'Title' : 'Name';

            throw_if($value === '', RuntimeException::class, "{$label} is required.");
        }

        if ($entity === ProposalEntity::Company && array_key_exists('account_owner_id', $editedCore)) {
            $error = WorkspaceMembersContext::memberFieldError($user, 'account_owner_id', $editedCore['account_owner_id']);

            throw_if($error !== null, RuntimeException::class, (string) $error);
        }
    }

    /**
     * @param  array<string, mixed>  $editedCustomFields
     * @return array<string, mixed>
     */
    private function validateCustomFields(User $user, ProposalEntity $entity, array $editedCustomFields): array
    {
        if ($editedCustomFields === []) {
            return [];
        }

        $result = $this->customFieldsValidator->validate($user, $entity->value, $editedCustomFields);

        throw_if($result->error !== null, RuntimeException::class, (string) $result->error);

        return $result->cleanFields;
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $editedCore
     * @param  array<string, mixed>  $editedCustomFields
     * @param  array<string, mixed>  $cleanFields
     * @return array<string, mixed>
     */
    private function rebuildRecord(
        User $user,
        ProposalEntity $entity,
        array $record,
        array $editedCore,
        array $editedCustomFields,
        array $cleanFields,
    ): array {
        $titleKey = $entity->titleKey();

        if (array_key_exists($titleKey, $editedCore)) {
            $record[$titleKey] = trim((string) $editedCore[$titleKey]);
        }

        if ($entity === ProposalEntity::Company && array_key_exists('account_owner_id', $editedCore)) {
            $ownerId = $editedCore['account_owner_id'];
            $record['account_owner_id'] = is_string($ownerId) && $ownerId !== '' ? $ownerId : $user->getKey();
        }

        if ($editedCustomFields !== []) {
            $merged = is_array($record['custom_fields'] ?? null) ? $record['custom_fields'] : [];

            // Only the edited codes change; every other custom field on the record is
            // preserved. A code edited to an empty/invalid value (dropped by the
            // validator, so absent from $cleanFields) is removed individually, never
            // the whole map.
            foreach (array_keys($editedCustomFields) as $code) {
                if (array_key_exists($code, $cleanFields)) {
                    $merged[$code] = $cleanFields[$code];

                    continue;
                }

                unset($merged[$code]);
            }

            if ($merged === []) {
                unset($record['custom_fields']);
            } else {
                $record['custom_fields'] = $merged;
            }
        }

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentDisplayFields(PendingAction $pendingAction, ?int $index): array
    {
        $display = ProposalPayload::from($pendingAction)->displayAt($index ?? 0);

        $fields = $display['fields'] ?? [];

        return is_array($fields) ? array_values($fields) : [];
    }

    /**
     * @param  array<string, mixed>  $rebuiltRecord
     * @param  array{title: string, summary: string, fields: list<array<string, mixed>>}  $rebuiltDisplay
     */
    private function persist(PendingAction $pendingAction, ?int $index, array $rebuiltRecord, array $rebuiltDisplay): void
    {
        if ($index === null) {
            $pendingAction->update([
                'action_data' => $rebuiltRecord,
                'display_data' => $rebuiltDisplay,
            ]);

            return;
        }

        $actionData = $pendingAction->action_data;
        $records = array_values($actionData['records'] ?? []);
        $records[$index] = $rebuiltRecord;
        $actionData['records'] = $records;

        $displayData = $pendingAction->display_data;
        $items = array_values($displayData['items'] ?? []);
        $items[$index] = $rebuiltDisplay;
        $displayData['items'] = $items;

        $pendingAction->update([
            'action_data' => $actionData,
            'display_data' => $displayData,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRecord(PendingAction $pendingAction, ?int $index): array
    {
        $payload = ProposalPayload::from($pendingAction);

        if (! $payload->isBatch) {
            return $pendingAction->action_data;
        }

        throw_if($index === null, RuntimeException::class, 'A batch item index is required');

        return $payload->batchRecordAt($index);
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
