<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\DeleteCustomField;
use App\Enums\CrmEntity;
use App\Enums\WorkspaceCapability;
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
use Relaticle\Chat\Tools\Concerns\RequiresWorkspaceCapability;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Relaticle\Chat\Tools\CustomField\Concerns\ResolvesOwnedCustomField;

final class DeleteCustomFieldTool implements Tool
{
    use ReportsValidationFailures;
    use RequiresWorkspaceCapability;
    use ResolvesOwnedCustomField;
    use WithConversationContext;

    public function name(): string
    {
        return 'DeleteCustomFieldTool';
    }

    public function description(): string
    {
        return 'Propose permanently deleting custom field definitions. Deleting a field also deletes its options and every value records hold for it, and cannot be undone. Owners and admins only. A system-defined field cannot be deleted. An active field that records still hold values for cannot be deleted either: deactivate it first with UpdateCustomFieldTool, then delete it. Call ListCustomFieldsTool first to get the field\'s code. Returns a proposal for user approval.';
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
                        ->description('The machine code of the custom field to delete, as ListCustomFieldsTool returns it (e.g. "industry").')
                        ->required(),
                ]))
                ->required()
                ->description(
                    'The field definitions to delete. Pass ONE item for a single field, or up to '
                    .config('chat.max_batch_size').' items to delete them all in ONE proposal (never loop one call per field).',
                ),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $capabilityError = $this->capabilityError($user, WorkspaceCapability::FieldsManage);

        if ($capabilityError !== null) {
            return $capabilityError;
        }

        $records = $request['records'] ?? null;

        if (! is_array($records) || $records === []) {
            return (string) json_encode(['error' => 'Provide `records`: a non-empty array of fields to delete, each with entity_type and code.'], JSON_UNESCAPED_SLASHES);
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($records) > $maxBatchSize) {
            return (string) json_encode(['error' => "Too many records: at most {$maxBatchSize} per proposal."], JSON_UNESCAPED_SLASHES);
        }

        $workspaceId = $user->currentWorkspace->getKey();
        $fields = [];

        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                return (string) json_encode(['error' => "records[{$index}] must be an object."], JSON_UNESCAPED_SLASHES);
            }

            $entityType = (string) ($record['entity_type'] ?? '');
            $code = (string) ($record['code'] ?? '');

            if ($entityType === '' || $code === '') {
                return (string) json_encode(['error' => "records[{$index}]: Both entity_type and code are required to identify the field."], JSON_UNESCAPED_SLASHES);
            }

            $field = $this->resolveOwnedCustomField($workspaceId, $entityType, $code);

            if (! $field instanceof CustomField) {
                return (string) json_encode(['error' => "records[{$index}]: No custom field with code \"{$code}\" found on {$entityType}."], JSON_UNESCAPED_SLASHES);
            }

            if (isset($fields[$field->getKey()])) {
                return (string) json_encode(['error' => "records[{$index}]: \"{$field->name}\" is already in this proposal."], JSON_UNESCAPED_SLASHES);
            }

            try {
                CustomFieldDefinitionValidator::forDelete($field);
            } catch (ValidationException $exception) {
                return $this->validationError($exception);
            }

            $fields[$field->getKey()] = $field;
        }

        $fields = array_values($fields);
        $items = array_map($this->displayItem(...), $fields);
        $isBatch = count($fields) > 1;

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: DeleteCustomField::class,
            operation: PendingActionOperation::Delete,
            entityType: 'custom_field',
            actionData: $isBatch
                ? [
                    '_batch' => true,
                    'records' => array_map(fn (CustomField $field): array => [
                        '_record_id' => $field->getKey(),
                        '_model_class' => CustomField::class,
                    ], $fields),
                ]
                : [
                    '_record_ids' => [$fields[0]->getKey()],
                    '_model_class' => CustomField::class,
                ],
            displayData: $isBatch
                ? [
                    'title' => __('Delete Custom Fields'),
                    'summary' => __('Delete :count custom fields', ['count' => count($items)]),
                    'items' => $items,
                ]
                : $items[0],
            turnId: $this->resolveTurnId(),
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'turn_id' => $pending->turn_id,
            'action' => 'DeleteCustomField',
            'entity_type' => 'custom_field',
            'operation' => 'delete',
            'data' => ['records' => array_map(fn (CustomField $field): array => [
                'entity_type' => $field->entity_type,
                'code' => $field->code,
            ], $fields)],
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array{title: string, summary: string, fields: list<array{label: string, value: string}>}
     */
    private function displayItem(CustomField $field): array
    {
        $holders = $field->values()->count();
        $options = $field->options()->pluck('name')->all();

        $rows = [
            ['label' => __('Name'), 'value' => (string) $field->name],
            ['label' => __('Record type'), 'value' => CrmEntity::from((string) $field->entity_type)->singularName()],
            ['label' => __('Values deleted'), 'value' => $holders === 0
                ? __('None. No record holds a value for it.')
                : trans_choice('{1} The value on :count record|[2,*] The values on :count records', $holders, ['count' => number_format($holders)])],
        ];

        if ($options !== []) {
            $rows[] = ['label' => __('Options deleted'), 'value' => implode(', ', $options)];
        }

        return [
            'title' => __('Delete Custom Field'),
            'summary' => __('Delete custom field ":name"', ['name' => $field->name]),
            'fields' => $rows,
        ];
    }
}
