<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\CustomFieldsSchemaDescriber;
use Relaticle\Chat\Support\ProposalPayload;
use Relaticle\Chat\Tools\Concerns\GuardsRecordNames;
use Relaticle\Chat\Tools\Concerns\LimitsPlanSteps;
use Relaticle\Chat\Tools\Concerns\ResolvesRecordNames;
use Relaticle\Chat\Tools\Concerns\ValidatesOwnedForeignKeys;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;

abstract class BaseWriteUpdateTool implements Tool
{
    use GuardsRecordNames;
    use LimitsPlanSteps;
    use ResolvesRecordNames;
    use ValidatesOwnedForeignKeys;
    use WithConversationContext;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string */
    abstract protected function actionClass(): string;

    abstract protected function entityType(): string;

    abstract protected function entityLabel(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    abstract protected function entitySchema(JsonSchema $schema): array;

    /**
     * Display rows for one record. Each row is `{label, old, new}` so the card
     * shows what changes, including what a cleared field or a re-linked set
     * gives up.
     *
     * @return array<string, mixed>
     */
    abstract protected function buildDisplayData(Request $request, Model $model): array;

    /** @return array<string, mixed> */
    abstract protected function extractActionData(Request $request): array;

    /**
     * Entity-specific request validation beyond custom fields. Return an
     * error message to abort the proposal, or null to proceed.
     */
    protected function validateRequest(Request $request, User $user): ?string
    {
        return null;
    }

    public function schema(JsonSchema $schema): array
    {
        $user = auth()->user();

        $customFieldsDescription = $user instanceof User
            ? resolve(CustomFieldsSchemaDescriber::class)->describe($user->currentTeam, $this->entityType())
            : 'Custom field values as key-value pairs.';

        $label = strtolower($this->entityLabel());

        $recordProperties = array_merge(
            ['id' => $schema->string()->description("The {$label} ULID to update.")->required()],
            $this->entitySchema($schema),
            ['custom_fields' => $schema->object()->description($customFieldsDescription)],
        );

        return [
            'records' => $schema->array()
                ->items($schema->object($recordProperties))
                ->required()
                ->description(
                    "The {$label} records to update. Pass ONE item for a single record,"
                    .' or up to '.config('chat.max_batch_size').' items to update them all in ONE proposal'
                    .' (never loop one call per record). Each item carries its id plus ONLY the fields that change:'
                    .' omit a field to leave it untouched, pass null to clear it.',
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
            return (string) json_encode(['error' => 'Provide `records`: a non-empty array of records to update, each with its id.'], JSON_UNESCAPED_SLASHES);
        }

        $maxBatchSize = (int) config('chat.max_batch_size');

        if (count($records) > $maxBatchSize) {
            return (string) json_encode(['error' => "Too many records: at most {$maxBatchSize} per proposal."], JSON_UNESCAPED_SLASHES);
        }

        $validator = resolve(CustomFieldsRequestValidator::class);
        $formatter = resolve(CustomFieldsDisplayFormatter::class);
        $modelClass = $this->modelClass();
        $label = $this->entityLabel();

        $actionRecords = [];
        $items = [];

        foreach (array_values($records) as $index => $record) {
            if (! is_array($record)) {
                return (string) json_encode(['error' => "records[{$index}] must be an object."], JSON_UNESCAPED_SLASHES);
            }

            $recordRequest = new Request($record);
            $id = $recordRequest->string('id');

            $model = $modelClass::query()
                ->whereBelongsTo($user->currentTeam)
                ->whereKey($id)
                ->first();

            if (! $model instanceof Model) {
                return (string) json_encode(['error' => "records[{$index}]: {$label} with ID [{$id}] not found."], JSON_UNESCAPED_SLASHES);
            }

            if ($user->cannot('update', $model)) {
                return (string) json_encode(['error' => "records[{$index}]: You do not have permission to update this {$label}."], JSON_UNESCAPED_SLASHES);
            }

            $nameError = $this->nameError($record);

            if ($nameError !== null) {
                return (string) json_encode(['error' => "records[{$index}]: {$nameError}"], JSON_UNESCAPED_SLASHES);
            }

            $validation = $validator->validate($user, $this->entityType(), $record['custom_fields'] ?? null);

            if ($validation->error !== null) {
                return (string) json_encode(['error' => "records[{$index}]: {$validation->error}"], JSON_UNESCAPED_SLASHES);
            }

            $requestError = $this->validateRequest($recordRequest, $user);

            if ($requestError !== null) {
                return (string) json_encode(['error' => "records[{$index}]: {$requestError}"], JSON_UNESCAPED_SLASHES);
            }

            $actionData = $this->extractActionData($recordRequest);
            $foreignKeyError = $this->foreignKeyError($user, $actionData);

            if ($foreignKeyError !== null) {
                return (string) json_encode(['error' => "records[{$index}]: {$foreignKeyError}"], JSON_UNESCAPED_SLASHES);
            }

            $actionData['_record_id'] = $model->getKey();
            $actionData['_model_class'] = $model::class;

            if ($validation->cleanFields !== []) {
                $actionData['custom_fields'] = $validation->cleanFields;
            }

            $displayData = $this->buildDisplayData($recordRequest, $model);
            $customFieldRows = $formatter->format($user, $this->entityType(), $validation->cleanFields, oldModel: $model);

            if ($customFieldRows !== []) {
                $existingFields = $displayData['fields'] ?? [];
                $displayData['fields'] = array_merge(is_array($existingFields) ? $existingFields : [], $customFieldRows);
            }

            $requestedRows = is_array($displayData['fields'] ?? null) ? array_values($displayData['fields']) : [];
            $displayData['fields'] = $this->rowsThatChange($requestedRows);

            if ($requestedRows === []) {
                return (string) json_encode(['error' => "records[{$index}]: Nothing to update. Pass at least one field that changes."], JSON_UNESCAPED_SLASHES);
            }

            if ($displayData['fields'] === []) {
                return (string) json_encode(['error' => "records[{$index}]: Already up to date. Every value passed matches what the {$label} has now, so there is nothing to change."], JSON_UNESCAPED_SLASHES);
            }

            $actionRecords[] = $actionData;
            $items[] = $displayData;
        }

        $isBatch = count($actionRecords) > 1;
        $entityNoun = strtolower($label);

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: $this->actionClass(),
            operation: PendingActionOperation::Update,
            entityType: $this->entityType(),
            actionData: $isBatch ? ['_batch' => true, 'records' => $actionRecords] : $actionRecords[0],
            displayData: $isBatch
                ? [
                    'title' => __('Update :entities', ['entities' => Str::plural($label, count($items))]),
                    'summary' => __('Update :count :entities', [
                        'count' => count($items),
                        'entities' => Str::plural($entityNoun, count($items)),
                    ]),
                    'items' => $items,
                ]
                : $items[0],
            turnId: $this->resolveTurnId(),
        );

        $publicRecords = array_map(ProposalPayload::withoutMarkers(...), $actionRecords);

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'turn_id' => $pending->turn_id,
            'action' => class_basename($this->actionClass()),
            'entity_type' => $this->entityType(),
            'operation' => 'update',
            'data' => $isBatch ? ['_batch' => true, 'records' => $publicRecords] : $publicRecords[0],
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * A row whose old and new values are identical is not a change; a model
     * that re-proposes an already-approved update would otherwise get a card
     * full of "X -> X" rows and ask the user to approve nothing. Rows that
     * carry raw `_oldValue`/`_newValue` are compared on those (rendered labels
     * collide: two companies or options can share a name); the raw keys are
     * stripped afterwards so only presentation is persisted.
     *
     * @param  list<mixed>  $rows
     * @return list<mixed>
     */
    private function rowsThatChange(array $rows): array
    {
        $changed = array_filter($rows, function (mixed $row): bool {
            if (! is_array($row)) {
                return true;
            }

            if (array_key_exists('_oldValue', $row) && array_key_exists('_newValue', $row)) {
                return ! $this->sameValue($row['_oldValue'], $row['_newValue']);
            }

            if (! array_key_exists('old', $row) || ! array_key_exists('new', $row)) {
                return true;
            }

            return $row['old'] !== $row['new'];
        });

        return array_values(array_map(static function (mixed $row): mixed {
            if (is_array($row)) {
                unset($row['_oldValue'], $row['_newValue']);
            }

            return $row;
        }, $changed));
    }

    /**
     * Lists compare as unordered id sets; everything else compares loosely
     * enough to treat 5 and "5" as the same stored value.
     */
    private function sameValue(mixed $old, mixed $new): bool
    {
        if (is_array($old) && is_array($new)) {
            $normalize = static function (array $values): array {
                $strings = array_map(static fn (mixed $value): string => (string) (is_scalar($value) ? $value : json_encode($value)), $values);
                sort($strings);

                return $strings;
            };

            return $normalize($old) === $normalize($new);
        }

        if (is_scalar($old) && is_scalar($new)) {
            return (string) $old === (string) $new;
        }

        return $old === $new;
    }
}
