<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\SetCustomFieldOptions;
use App\Enums\WorkspaceCapability;
use App\Models\CustomField;
use App\Models\User;
use App\Support\CustomFieldOptionPlan;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\PendingActionEnvelope;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\ReportsValidationFailures;
use Relaticle\Chat\Tools\Concerns\RequiresWorkspaceCapability;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Relaticle\Chat\Tools\CustomField\Concerns\ResolvesOwnedCustomField;

final class SetCustomFieldOptionsTool implements Tool
{
    use LimitsPlanSteps;
    use ReportsValidationFailures;
    use RequiresWorkspaceCapability;
    use ResolvesOwnedCustomField;
    use WithConversationContext;

    public function name(): string
    {
        return 'SetCustomFieldOptionsTool';
    }

    public function description(): string
    {
        return 'Propose changing the options of existing choice fields (select, multi-select, radio, checkbox-list, toggle-buttons): rename, reorder, add and remove them in ONE proposal. Pass the complete list each field should end with, in order. An existing option you leave out is removed, so list every option that stays. Owners and admins only. Call ListCustomFieldsTool first: it returns each field\'s current options. The task Status option "Done" can be neither renamed nor removed. Returns a proposal for user approval.';
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
                        ->description('The machine code of the choice field, as ListCustomFieldsTool returns it (e.g. "stage").')
                        ->required(),
                    'options' => $schema->array()
                        ->items($schema->object([
                            'name' => $schema->string()
                                ->description('The option label the field should have.')
                                ->required(),
                            'current' => $schema->string()
                                ->description('To rename an existing option in place, its current label. Its records keep it under the new name.'),
                        ]))
                        ->description('The COMPLETE option list in display order. An existing option stays when an item names it in `name` or `current`. Every other existing option is removed.')
                        ->required(),
                    'replacements' => $schema->object()
                        ->description('For each removed option that records still use: its current label mapped to the label of an option in `options` those records move to, e.g. {"Negotiation": "Proposal"}.'),
                ]))
                ->required()
                ->description(
                    'The fields to change. Pass ONE item for a single field, or up to '
                    .config('chat.max_batch_size').' items to change them all in ONE proposal (never loop one call per field).',
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

        $planLimitError = $this->planStepLimitError();

        if ($planLimitError !== null) {
            return (string) json_encode(['error' => $planLimitError], JSON_UNESCAPED_SLASHES);
        }

        $records = $request['records'] ?? null;

        if (! is_array($records) || $records === []) {
            return (string) json_encode(['error' => 'Provide `records`: a non-empty array of fields to change, each with entity_type, code and options.'], JSON_UNESCAPED_SLASHES);
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($records) > $maxBatchSize) {
            return (string) json_encode(['error' => "Too many records: at most {$maxBatchSize} per proposal."], JSON_UNESCAPED_SLASHES);
        }

        $workspaceId = $user->currentWorkspace->getKey();
        $actionRecords = [];
        $items = [];
        $fieldIds = [];

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

            if (in_array($field->getKey(), $fieldIds, true)) {
                return (string) json_encode(['error' => "records[{$index}]: \"{$field->name}\" is already in this proposal. Give each field one record with its whole option list."], JSON_UNESCAPED_SLASHES);
            }

            try {
                $payload = CustomFieldOptionPlan::fromToolInput($field, $record);
                $plan = CustomFieldOptionPlan::validated($field, $payload);
            } catch (ValidationException $exception) {
                return $this->validationError($exception);
            }

            $fieldIds[] = $field->getKey();
            $actionRecords[] = [
                '_record_id' => $field->getKey(),
                '_model_class' => CustomField::class,
                ...$payload,
            ];
            $items[] = [
                'title' => __('Change Field Options'),
                'summary' => __('Change options of ":field"', ['field' => $field->name]),
                'fields' => $plan->displayRows(),
            ];
        }

        $isBatch = count($actionRecords) > 1;

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: SetCustomFieldOptions::class,
            operation: PendingActionOperation::Update,
            entityType: 'custom_field',
            actionData: $isBatch ? ['_batch' => true, 'records' => $actionRecords] : $actionRecords[0],
            displayData: $isBatch
                ? [
                    'title' => __('Change Field Options'),
                    'summary' => __('Change options of :count fields', ['count' => count($items)]),
                    'items' => $items,
                ]
                : $items[0],
            turnId: $this->resolveTurnId(),
        );

        $publicRecords = array_map(ProposalPayload::withoutMarkers(...), $actionRecords);

        return (string) json_encode(
            PendingActionEnvelope::for($pending, 'SetCustomFieldOptions', $isBatch ? ['_batch' => true, 'records' => $publicRecords] : $publicRecords[0]),
            JSON_UNESCAPED_SLASHES,
        );
    }
}
