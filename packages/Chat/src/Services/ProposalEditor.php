<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services;

use App\Models\CustomField;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\ProposalDisplayBuilder;
use Relaticle\Chat\Services\Tools\ProposalFieldSchemaDescriber;
use Relaticle\Chat\Support\ProposalCoreFields;
use Relaticle\Chat\Support\TeamMembersContext;
use Relaticle\CustomFields\Enums\FieldDataType;
use Relaticle\CustomFields\Facades\CustomFieldsType;
use Relaticle\CustomFields\Services\TenantContextService;
use RuntimeException;

/**
 * Orchestrates editing a chat create-proposal before approval. Task 3 exposes
 * the structured editable schema (single, or one item of a batch); Task 4 adds
 * the edit path: re-validate the edited core + custom fields, rewrite the clean
 * action_data, and re-render display_data — never executing the action and
 * never dispatching a continuation.
 */
final readonly class ProposalEditor
{
    public function __construct(
        private ProposalFieldSchemaDescriber $describer,
        private ProposalDisplayBuilder $displayBuilder,
        private CustomFieldsRequestValidator $customFieldsValidator,
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
     * Re-validate the edited fields, rewrite action_data, and re-render
     * display_data for a single create-proposal (or one batch item). Returns
     * the refreshed PendingAction; it stays Pending — the action is never run.
     *
     * @param  array<string, mixed>  $input  the edited fields keyed by code
     */
    public function applyEdit(PendingAction $pendingAction, User $user, array $input, ?int $index = null): PendingAction
    {
        $previousTenantId = TenantContextService::getCurrentTenantId();
        TenantContextService::setTenantId($pendingAction->team_id);

        try {
            return DB::transaction(function () use ($pendingAction, $user, $input, $index): PendingAction {
                /** @var PendingAction $locked */
                $locked = PendingAction::query()->lockForUpdate()->findOrFail($pendingAction->getKey());

                $this->assertEditable($locked);

                $record = $this->resolveRecord($locked, $index);
                $entityType = $locked->entity_type;

                [$editedCore, $editedCustomFields] = $this->splitInput($entityType, $input);

                $this->validateCore($user, $entityType, $editedCore);

                $cleanFields = $this->validateCustomFields($user, $entityType, $editedCustomFields);

                $rebuiltRecord = $this->rebuildRecord($user, $entityType, $record, $editedCore, $editedCustomFields, $cleanFields);

                $rebuiltDisplay = $this->displayBuilder->build(
                    $user,
                    $entityType,
                    $rebuiltRecord,
                    $this->currentDisplayFields($locked, $index),
                );

                $this->persist($locked, $index, $rebuiltRecord, $rebuiltDisplay);

                return $locked->refresh();
            });
        } finally {
            TenantContextService::setTenantId($previousTenantId);
        }
    }

    /**
     * Split the edited fields into core (title/name + company account_owner_id)
     * and custom (everything else, keyed by custom-field code).
     *
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitInput(string $entityType, array $input): array
    {
        $core = [];
        $custom = [];

        foreach ($input as $code => $value) {
            if (ProposalCoreFields::isCore($entityType, $code)) {
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
    private function validateCore(User $user, string $entityType, array $editedCore): void
    {
        $titleKey = ProposalCoreFields::titleKey($entityType);

        if (array_key_exists($titleKey, $editedCore)) {
            $value = trim((string) $editedCore[$titleKey]);
            $label = $titleKey === 'title' ? 'Title' : 'Name';

            throw_if($value === '', RuntimeException::class, "{$label} is required.");
        }

        if ($entityType === 'company' && array_key_exists('account_owner_id', $editedCore)) {
            $error = TeamMembersContext::memberFieldError($user, 'account_owner_id', $editedCore['account_owner_id']);

            throw_if($error !== null, RuntimeException::class, (string) $error);
        }
    }

    /**
     * Validate the edited custom fields through the shared request validator.
     * Per the locked choice ID↔label contract, incoming choice option IDs are
     * converted back to labels first, because the validator re-translates
     * labels → IDs and applies the configured rules.
     *
     * @param  array<string, mixed>  $editedCustomFields
     * @return array<string, mixed>
     */
    private function validateCustomFields(User $user, string $entityType, array $editedCustomFields): array
    {
        if ($editedCustomFields === []) {
            return [];
        }

        $fields = CustomField::query()
            ->where('tenant_id', $user->currentTeam->getKey())
            ->where('entity_type', $entityType)
            ->active()
            ->whereIn('code', array_keys($editedCustomFields))
            ->with('options')
            ->get();

        $converted = $this->convertChoiceIdsToLabels($editedCustomFields, $fields);

        $result = $this->customFieldsValidator->validate($user, $entityType, $converted);

        throw_if($result->error !== null, RuntimeException::class, (string) $result->error);

        return $result->cleanFields;
    }

    /**
     * Convert incoming choice option IDs to labels for SELECT/MULTI_SELECT
     * fields. Non-choice values (link arrays, text, number, bool, date) and
     * lookup-backed choices pass through unchanged. An ID that matches no
     * option is left as-is so the downstream validator rejects it.
     *
     * @param  array<string, mixed>  $raw
     * @param  Collection<int, CustomField>  $fields
     * @return array<string, mixed>
     */
    private function convertChoiceIdsToLabels(array $raw, Collection $fields): array
    {
        $byCode = $fields->keyBy('code');
        $converted = [];

        foreach ($raw as $code => $value) {
            $field = $byCode->get($code);

            if (! $field instanceof CustomField) {
                $converted[$code] = $value;

                continue;
            }

            $typeData = CustomFieldsType::getFieldType($field->type);
            $dataType = $typeData?->dataType;

            if ($dataType === null
                || ! $dataType->isChoiceField()
                || $typeData->acceptsArbitraryValues
                || $field->lookup_type !== null) {
                $converted[$code] = $value;

                continue;
            }

            $labelById = $this->optionLabelsById($field);

            $converted[$code] = $dataType === FieldDataType::MULTI_CHOICE
                ? $this->idsToLabels($value, $labelById)
                : $this->idToLabel($value, $labelById);
        }

        return $converted;
    }

    /**
     * @return array<int|string, string>
     */
    private function optionLabelsById(CustomField $field): array
    {
        $map = [];

        foreach ($field->options as $option) {
            $map[(string) $option->id] = (string) $option->name;
        }

        return $map;
    }

    /**
     * @param  array<int|string, string>  $labelById
     */
    private function idToLabel(mixed $value, array $labelById): mixed
    {
        if (! is_string($value) && ! is_int($value)) {
            return $value;
        }

        return $labelById[(string) $value] ?? $value;
    }

    /**
     * @param  array<int|string, string>  $labelById
     */
    private function idsToLabels(mixed $value, array $labelById): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return array_map(fn (mixed $item): mixed => $this->idToLabel($item, $labelById), $value);
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
        string $entityType,
        array $record,
        array $editedCore,
        array $editedCustomFields,
        array $cleanFields,
    ): array {
        $titleKey = ProposalCoreFields::titleKey($entityType);

        if (array_key_exists($titleKey, $editedCore)) {
            $record[$titleKey] = trim((string) $editedCore[$titleKey]);
        }

        if ($entityType === 'company' && array_key_exists('account_owner_id', $editedCore)) {
            $ownerId = $editedCore['account_owner_id'];
            $record['account_owner_id'] = is_string($ownerId) && $ownerId !== '' ? $ownerId : $user->getKey();
        }

        if ($editedCustomFields !== []) {
            if ($cleanFields === []) {
                unset($record['custom_fields']);
            } else {
                $record['custom_fields'] = $cleanFields;
            }
        }

        return $record;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function currentDisplayFields(PendingAction $pendingAction, ?int $index): array
    {
        $display = $pendingAction->display_data;

        if ($index === null) {
            $fields = $display['fields'] ?? [];

            return is_array($fields) ? array_values($fields) : [];
        }

        $items = is_array($display['items'] ?? null) ? array_values($display['items']) : [];
        $item = $items[$index] ?? null;
        $fields = is_array($item) ? ($item['fields'] ?? []) : [];

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
