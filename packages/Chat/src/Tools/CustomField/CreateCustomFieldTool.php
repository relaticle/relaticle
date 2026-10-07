<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\WorkspaceCapability;
use App\Models\User;
use App\Support\CustomFieldDefinitionValidator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Support\PendingActionEnvelope;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\ReportsSkippedRecords;
use Relaticle\Chat\Tools\Concerns\RequiresWorkspaceCapability;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Relaticle\CustomFields\Support\CodeGenerator;

final class CreateCustomFieldTool implements Tool
{
    use LimitsPlanSteps;
    use ReportsSkippedRecords;
    use RequiresWorkspaceCapability;
    use WithConversationContext;

    public function name(): string
    {
        return 'CreateCustomFieldTool';
    }

    public function description(): string
    {
        return 'Propose creating one or more custom field definitions on CRM entities, all in one proposal. Field names and codes must be unique per entity, so check ListCustomFieldsTool first. Admin-only: returns an error for members and viewers. Returns a proposal for user approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        $allowedTypes = implode(', ', CreateCustomField::ALLOWED_TYPES);

        return [
            'records' => $schema->array()
                ->items($schema->object([
                    'entity_type' => $schema->string()
                        ->description('The CRM entity to add the field to: company, people, opportunity, task, or note.')
                        ->required(),
                    'name' => $schema->string()
                        ->description('The display name for the field (e.g. "Industry", "Priority"). Max 50 characters, and must not match an existing field on the same entity.')
                        ->required(),
                    'type' => $schema->string()
                        ->description("The field type. Allowed: {$allowedTypes}. NOT allowed: file-upload, record, rich-editor, currency.")
                        ->required(),
                    'code' => $schema->string()
                        ->description('Optional machine-readable code (snake_case). Auto-generated from name if omitted.'),
                    'options' => $schema->array()
                        ->items($schema->object([
                            'name' => $schema->string()->description('The option label.')->required(),
                        ]))
                        ->description('Required for choice types (select, multi-select, radio, checkbox-list, toggle-buttons). Must not be provided for other types.'),
                ]))
                ->required()
                ->description(
                    'The field definitions to create. Pass ONE item for a single field, or up to '
                    .config('chat.max_batch_size').' items to create them all in ONE proposal (never loop one call per field).',
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
            return (string) json_encode(['error' => 'Provide `records`: a non-empty array of fields to create, each with entity_type, name and type.'], JSON_UNESCAPED_SLASHES);
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($records) > $maxBatchSize) {
            return (string) json_encode(['error' => "Too many records: at most {$maxBatchSize} per proposal."], JSON_UNESCAPED_SLASHES);
        }

        $actionRecords = [];
        $items = [];

        /** @var list<array{record: string, reason: string}> $skipped */
        $skipped = [];

        /** @var array<string, int> $proposedPerEntity */
        $proposedPerEntity = [];

        /** @var array<string, true> $proposedNames */
        $proposedNames = [];

        /** @var array<string, bool> $explicitByCode */
        $explicitByCode = [];

        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                return (string) json_encode(['error' => "records[{$index}] must be an object."], JSON_UNESCAPED_SLASHES);
            }

            $entityType = is_string($record['entity_type'] ?? null) ? $record['entity_type'] : '';

            try {
                $validated = CustomFieldDefinitionValidator::forCreate($user, [
                    'entity_type' => $record['entity_type'] ?? null,
                    'name' => $record['name'] ?? null,
                    'type' => $record['type'] ?? null,
                    'code' => $record['code'] ?? null,
                    'options' => $record['options'] ?? null,
                ], $proposedPerEntity[$entityType] ?? 0);
            } catch (ValidationException $exception) {
                $skipped[] = $this->skippedRecord($record, $index, implode(' ', Arr::flatten($exception->errors())));

                continue;
            }

            $name = (string) $validated['name'];
            $nameKey = $entityType.':'.mb_strtolower($name);

            if (isset($proposedNames[$nameKey])) {
                $skipped[] = $this->skippedRecord($record, $index, "A field named \"{$name}\" is already in this batch on {$entityType}.");

                continue;
            }

            $explicitCode = (string) ($validated['code'] ?? '');
            $code = $explicitCode !== '' ? $explicitCode : CodeGenerator::generateFromName($name);
            $codeKey = $entityType.':'.$code;
            if (isset($explicitByCode[$codeKey]) && ($explicitByCode[$codeKey] || $explicitCode !== '')) {
                $skipped[] = $this->skippedRecord($record, $index, "A field with code \"{$code}\" is already in this batch on {$entityType}.");

                continue;
            }

            $proposedNames[$nameKey] = true;
            $explicitByCode[$codeKey] = $explicitCode !== '';

            $proposedPerEntity[$entityType] = ($proposedPerEntity[$entityType] ?? 0) + 1;
            $actionRecords[] = $this->actionData($validated);
            $items[] = $this->display($validated);
        }

        if ($actionRecords === []) {
            return $this->everyRecordFailedError($skipped);
        }

        $isBatch = count($actionRecords) > 1;

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: CreateCustomField::class,
            operation: PendingActionOperation::Create,
            entityType: 'custom_field',
            actionData: $isBatch ? ['_batch' => true, 'records' => $actionRecords] : $actionRecords[0],
            displayData: $isBatch
                ? [
                    'title' => __('Create Custom Fields'),
                    'summary' => __('Create :count custom fields', ['count' => count($items)]),
                    'items' => $items,
                ]
                : $items[0],
            turnId: $this->resolveTurnId(),
        );

        return (string) json_encode(
            $this->withSkippedRecords(PendingActionEnvelope::for($pending, 'CreateCustomField', $pending->action_data), $skipped),
            JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function actionData(array $validated): array
    {
        $code = (string) ($validated['code'] ?? '');
        $options = is_array($validated['options'] ?? null) ? $validated['options'] : [];

        return array_filter([
            'entity_type' => (string) $validated['entity_type'],
            'name' => (string) $validated['name'],
            'type' => (string) $validated['type'],
            'code' => $code !== '' ? $code : null,
            'options' => $options !== [] ? $options : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function display(array $validated): array
    {
        $entityType = (string) $validated['entity_type'];
        $name = (string) $validated['name'];
        $type = (string) $validated['type'];
        $code = (string) ($validated['code'] ?? '');
        $optionNames = array_column(is_array($validated['options'] ?? null) ? $validated['options'] : [], 'name');

        $fields = [
            ['label' => __('Entity'), 'value' => $entityType],
            ['label' => __('Name'), 'value' => $name],
            ['label' => __('Type'), 'value' => $type],
        ];

        if ($code !== '') {
            $fields[] = ['label' => __('Code'), 'value' => $code];
        }

        if ($optionNames !== []) {
            $fields[] = ['label' => __('Options'), 'value' => implode(', ', $optionNames)];
        }

        $replace = ['name' => $name, 'type' => $type, 'entity' => $entityType, 'options' => implode(', ', $optionNames)];

        return [
            'title' => __('Create Custom Field'),
            'summary' => $optionNames === []
                ? __('Create ":name" (:type) on :entity', $replace)
                : __('Create ":name" (:type) on :entity with options: :options', $replace),
            'fields' => $fields,
        ];
    }
}
