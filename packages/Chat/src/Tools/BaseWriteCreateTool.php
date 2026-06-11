<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\CustomFieldsSchemaDescriber;
use Relaticle\Chat\Support\PromptText;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

abstract class BaseWriteCreateTool implements Tool
{
    use WithConversationContext;

    private const int MAX_BATCH_SIZE = 25;

    /** @return class-string */
    abstract protected function actionClass(): string;

    abstract protected function entityType(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    abstract protected function entitySchema(JsonSchema $schema): array;

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    abstract protected function buildRecordDisplay(array $record): array;

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    abstract protected function extractRecordData(array $record): array;

    public function schema(JsonSchema $schema): array
    {
        $user = auth()->user();

        $customFieldsDescription = $user instanceof User
            ? resolve(CustomFieldsSchemaDescriber::class)->describe($user->currentTeam, $this->entityType())
            : 'Custom field values as key-value pairs.';

        $recordProperties = array_merge(
            $this->entitySchema($schema),
            ['custom_fields' => $schema->object()->description($customFieldsDescription)],
        );

        return [
            'records' => $schema->array()
                ->items($schema->object($recordProperties))
                ->required()
                ->description(
                    "The {$this->entityType()} records to create. Pass ONE item for a single record,"
                    .' or up to '.self::MAX_BATCH_SIZE.' items to create them all in ONE proposal'
                    .' (never loop one call per record).',
                ),
            'plan' => $schema->object([
                'original_request' => $schema->string()->description("The user's original multi-step request, verbatim."),
                'position' => $schema->integer()->description('Which step this proposal is (1-based).'),
                'total' => $schema->integer()->description('Total steps in the request.'),
            ])->description('OPTIONAL — only when this proposal is one step of a multi-step request that records[] cannot cover in one call (e.g. mixed entity types).'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $records = $request['records'] ?? null;

        if (! is_array($records) || $records === []) {
            return (string) json_encode(['error' => 'Provide `records` — a non-empty array of records to create.']);
        }

        if (count($records) > self::MAX_BATCH_SIZE) {
            return (string) json_encode(['error' => 'Too many records — at most '.self::MAX_BATCH_SIZE.' per proposal.']);
        }

        $validator = resolve(CustomFieldsRequestValidator::class);
        $formatter = resolve(CustomFieldsDisplayFormatter::class);

        $actionRecords = [];
        $items = [];

        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                return (string) json_encode(['error' => "records[{$index}] must be an object."]);
            }

            $validation = $validator->validate($user, $this->entityType(), $record['custom_fields'] ?? null);

            if ($validation->error !== null) {
                return (string) json_encode(['error' => "records[{$index}]: {$validation->error}"]);
            }

            $data = $this->extractRecordData($record);
            if ($validation->cleanFields !== []) {
                $data['custom_fields'] = $validation->cleanFields;
            }

            $display = $this->buildRecordDisplay($record);
            $customFieldRows = $formatter->format($user, $this->entityType(), $validation->cleanFields, oldModel: null);
            if ($customFieldRows !== []) {
                $existingFields = $display['fields'] ?? [];
                $display['fields'] = array_merge(is_array($existingFields) ? $existingFields : [], $customFieldRows);
            }

            $actionRecords[] = $data;
            $items[] = $display;
        }

        $isBatch = count($actionRecords) > 1;

        $actionData = $isBatch
            ? ['_batch' => true, 'records' => $actionRecords]
            : $actionRecords[0];

        $displayData = $isBatch
            ? [
                'title' => 'Create '.Str::plural(Str::headline($this->entityType()), count($items)),
                'summary' => sprintf('Create %d %s', count($items), Str::plural($this->entityType(), count($items))),
                'items' => $items,
            ]
            : $items[0];

        $plan = $request['plan'] ?? null;
        if (is_array($plan)
            && is_string($plan['original_request'] ?? null)
            && is_numeric($plan['position'] ?? null) && is_numeric($plan['total'] ?? null)) {
            $sanitized = $this->sanitizePlanText($plan['original_request']);
            if ($sanitized !== '') {
                $displayData['plan'] = [
                    'original_request' => $sanitized,
                    'position' => (int) $plan['position'],
                    'total' => (int) $plan['total'],
                ];
            }
        }

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: $this->actionClass(),
            operation: PendingActionOperation::Create,
            entityType: $this->entityType(),
            actionData: $actionData,
            displayData: $displayData,
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => class_basename($this->actionClass()),
            'entity_type' => $this->entityType(),
            'operation' => 'create',
            'data' => $pending->action_data,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_PRETTY_PRINT);
    }

    private function sanitizePlanText(string $text): string
    {
        return PromptText::sanitize($text, 300);
    }
}
