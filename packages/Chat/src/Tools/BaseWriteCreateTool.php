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
use Relaticle\Chat\Tools\Concerns\GuardsRecordNames;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\ResolvesRecordNames;
use Relaticle\Chat\Tools\Concerns\ValidatesOwnedForeignKeys;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

abstract class BaseWriteCreateTool implements Tool
{
    use GuardsRecordNames;
    use LimitsPlanSteps;
    use ResolvesRecordNames;
    use ValidatesOwnedForeignKeys;
    use WithConversationContext;

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

    /**
     * Entity-specific per-record validation beyond custom fields. Return an
     * error message to skip the record, or null to proceed.
     *
     * @param  array<string, mixed>  $record
     */
    protected function validateRecord(array $record, User $user): ?string
    {
        return null;
    }

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
                    .' or up to '.config('chat.max_batch_size').' items to create them all in ONE proposal'
                    .' (never loop one call per record).',
                ),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $planLimitError = $this->planStepLimitError();

        if ($planLimitError !== null) {
            return (string) json_encode(['error' => $planLimitError], JSON_UNESCAPED_SLASHES);
        }

        $records = $request['records'] ?? null;

        if (! is_array($records) || $records === []) {
            return (string) json_encode(['error' => 'Provide `records`: a non-empty array of records to create.'], JSON_UNESCAPED_SLASHES);
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($records) > $maxBatchSize) {
            return (string) json_encode(['error' => "Too many records: at most {$maxBatchSize} per proposal."], JSON_UNESCAPED_SLASHES);
        }

        $validator = resolve(CustomFieldsRequestValidator::class);
        $formatter = resolve(CustomFieldsDisplayFormatter::class);

        $actionRecords = [];
        $items = [];
        $skipped = [];

        // Per-record validation failures skip the record, never the whole call:
        // the real field rules (including unique-value checks, which is how a
        // duplicate person email is rejected) run here, the valid records still
        // become ONE proposal, and the skipped ones are reported back with their
        // reasons so the model can tell the user exactly what was left out.
        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                return (string) json_encode(['error' => "records[{$index}] must be an object."], JSON_UNESCAPED_SLASHES);
            }

            $nameError = $this->nameError($record, required: true);

            if ($nameError !== null) {
                $skipped[] = $this->skippedRecord($record, $index, $nameError);

                continue;
            }

            $validation = $validator->validate($user, $this->entityType(), $record['custom_fields'] ?? null, isUpdate: false);

            if ($validation->error !== null) {
                $skipped[] = $this->skippedRecord($record, $index, $validation->error);

                continue;
            }

            $recordError = $this->validateRecord($record, $user);

            if ($recordError !== null) {
                $skipped[] = $this->skippedRecord($record, $index, $recordError);

                continue;
            }

            $data = $this->extractRecordData($record);
            $foreignKeyError = $this->foreignKeyError($user, $data);

            if ($foreignKeyError !== null) {
                $skipped[] = $this->skippedRecord($record, $index, $foreignKeyError);

                continue;
            }

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

        if ($actionRecords === []) {
            $reasons = implode(' ', array_map(
                static fn (array $skip): string => "{$skip['record']}: ".rtrim($skip['reason'], '.').'.',
                $skipped,
            ));

            return (string) json_encode([
                'error' => "No proposal was created; every record failed validation. {$reasons}"
                    .' Tell the user each reason. Do not retry with the same values.',
            ], JSON_UNESCAPED_SLASHES);
        }

        $isBatch = count($actionRecords) > 1;

        $actionData = $isBatch
            ? ['_batch' => true, 'records' => $actionRecords]
            : $actionRecords[0];

        $displayData = $isBatch
            ? [
                'title' => __('Create :entities', ['entities' => Str::plural(Str::headline($this->entityType()), count($items))]),
                'summary' => sprintf('Create %d %s', count($items), Str::plural(Str::lower(Str::headline($this->entityType())), count($items))),
                'items' => $items,
            ]
            : $items[0];

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: $this->actionClass(),
            operation: PendingActionOperation::Create,
            entityType: $this->entityType(),
            actionData: $actionData,
            displayData: $displayData,
            turnId: $this->resolveTurnId(),
        );

        $envelope = [
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'turn_id' => $pending->turn_id,
            'action' => class_basename($this->actionClass()),
            'entity_type' => $this->entityType(),
            'operation' => 'create',
            'data' => $pending->action_data,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ];

        if ($skipped !== []) {
            $envelope['skipped_records'] = $skipped;
            $envelope['skipped_note'] = 'These records failed validation and are NOT part of the proposal.'
                .' Tell the user each skipped record and its reason.';
        }

        return (string) json_encode($envelope, JSON_UNESCAPED_SLASHES);
    }

    /**
     * The label a skipped record is reported under: whatever identity the model
     * gave it, or its position when it has none.
     *
     * @param  array<string, mixed>  $record
     * @return array{record: string, reason: string}
     */
    private function skippedRecord(array $record, int $index, string $reason): array
    {
        $label = null;

        foreach (['name', 'title', 'email'] as $key) {
            if (is_string($record[$key] ?? null) && trim($record[$key]) !== '') {
                $label = trim($record[$key]);

                break;
            }
        }

        return [
            'record' => $label ?? 'record '.($index + 1),
            'reason' => $reason,
        ];
    }
}
