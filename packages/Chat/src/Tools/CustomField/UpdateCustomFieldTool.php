<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\UpdateCustomField;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Concerns\ReportsValidationFailures;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Relaticle\Chat\Tools\CustomField\Concerns\ResolvesOwnedCustomField;

final class UpdateCustomFieldTool implements Tool
{
    use ReportsValidationFailures;
    use ResolvesOwnedCustomField;
    use WithConversationContext;

    public function name(): string
    {
        return 'UpdateCustomFieldTool';
    }

    public function description(): string
    {
        return 'Propose renaming a custom field or toggling its active status. Admin-only. Cannot modify system-defined fields. Returns a proposal for user approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'records' => $schema->array()
                ->items($schema->object([
                    'entity_type' => $schema->string()
                        ->description('The CRM entity the field belongs to: company, people, opportunity, task, or note.')
                        ->required(),
                    'code' => $schema->string()
                        ->description('The machine code of the custom field to update, as shown in the custom_fields field list for that entity (e.g. "industry").')
                        ->required(),
                    'name' => $schema->string()
                        ->description('The new display name for the field.'),
                    'active' => $schema->boolean()
                        ->description('Set to false to deactivate the field, or true to reactivate it.'),
                ]))
                ->required()
                ->description(
                    'The field definitions to update. Pass ONE item for a single field, or up to '
                    .config('chat.max_batch_size').' items to update them all in ONE proposal (never loop one call per field).',
                ),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->ownsTeam($user->currentTeam)) {
            return (string) json_encode([
                'error' => 'Only team owners can update custom field definitions.',
            ], JSON_UNESCAPED_SLASHES);
        }

        $records = $request['records'] ?? null;

        if (! is_array($records) || $records === []) {
            return (string) json_encode(['error' => 'Provide `records`: a non-empty array of fields to update, each with entity_type and code.'], JSON_UNESCAPED_SLASHES);
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($records) > $maxBatchSize) {
            return (string) json_encode(['error' => "Too many records: at most {$maxBatchSize} per proposal."], JSON_UNESCAPED_SLASHES);
        }

        $teamId = $user->currentTeam->getKey();
        $actionRecords = [];
        $items = [];

        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                return (string) json_encode(['error' => "records[{$index}] must be an object."], JSON_UNESCAPED_SLASHES);
            }

            $entityType = (string) ($record['entity_type'] ?? '');
            $code = (string) ($record['code'] ?? '');

            if ($entityType === '' || $code === '') {
                return (string) json_encode(['error' => "records[{$index}]: Both entity_type and code are required to identify the field."], JSON_UNESCAPED_SLASHES);
            }

            $field = $this->resolveOwnedCustomField($teamId, $entityType, $code);

            if (! $field instanceof CustomField) {
                return (string) json_encode(['error' => "records[{$index}]: No custom field with code \"{$code}\" found on {$entityType}."], JSON_UNESCAPED_SLASHES);
            }

            if ($field->isSystemDefined()) {
                return (string) json_encode(['error' => "records[{$index}]: System-defined custom fields cannot be modified."], JSON_UNESCAPED_SLASHES);
            }

            try {
                $validated = CustomFieldDefinitionValidator::forRename($user, $field, array_filter([
                    'name' => $record['name'] ?? null,
                    'active' => $record['active'] ?? null,
                ], fn (mixed $value): bool => $value !== null));
            } catch (ValidationException $exception) {
                return $this->validationError($exception);
            }

            $newName = isset($validated['name']) ? (string) $validated['name'] : null;
            $newActive = isset($validated['active']) ? (bool) $validated['active'] : null;

            $actionData = [
                '_record_id' => $field->getKey(),
                '_model_class' => CustomField::class,
            ];

            $displayFields = [];

            if ($newName !== null) {
                $actionData['name'] = $newName;
                $displayFields[] = ['label' => 'Name', 'old' => $field->name, 'new' => $newName];
            }

            if ($newActive !== null) {
                $actionData['active'] = $newActive;
                $displayFields[] = [
                    'label' => 'Active',
                    'old' => $field->active ? 'Yes' : 'No',
                    'new' => $newActive ? 'Yes' : 'No',
                ];
            }

            if ($displayFields === []) {
                return (string) json_encode(['error' => "records[{$index}]: Nothing to update. Pass a new name or an active flag."], JSON_UNESCAPED_SLASHES);
            }

            $actionRecords[] = $actionData;
            $items[] = [
                'title' => 'Update Custom Field',
                'summary' => "Update custom field \"{$field->name}\"",
                'fields' => $displayFields,
            ];
        }

        $isBatch = count($actionRecords) > 1;

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: UpdateCustomField::class,
            operation: PendingActionOperation::Update,
            entityType: 'custom_field',
            actionData: $isBatch ? ['_batch' => true, 'records' => $actionRecords] : $actionRecords[0],
            displayData: $isBatch
                ? [
                    'title' => __('Update Custom Fields'),
                    'summary' => __('Update :count custom fields', ['count' => count($items)]),
                    'items' => $items,
                ]
                : $items[0],
            turnId: $this->resolveTurnId(),
        );

        $publicRecords = array_map(
            static fn (array $record): array => array_diff_key($record, array_flip(['_record_id', '_model_class'])),
            $actionRecords,
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'turn_id' => $pending->turn_id,
            'action' => 'UpdateCustomField',
            'entity_type' => 'custom_field',
            'operation' => 'update',
            'data' => $isBatch ? ['_batch' => true, 'records' => $publicRecords] : $publicRecords[0],
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_UNESCAPED_SLASHES);
    }
}
