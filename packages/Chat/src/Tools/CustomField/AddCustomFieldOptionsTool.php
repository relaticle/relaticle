<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\AddCustomFieldOptions;
use App\Actions\CustomFields\CreateCustomField;
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

final class AddCustomFieldOptionsTool implements Tool
{
    use ReportsValidationFailures;
    use ResolvesOwnedCustomField;
    use WithConversationContext;

    public function name(): string
    {
        return 'AddCustomFieldOptionsTool';
    }

    public function description(): string
    {
        return 'Propose adding new options to an existing choice-type custom field (select, multi-select, radio, checkbox-list, toggle-buttons). Admin-only. Returns a proposal for user approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()
                ->description('The CRM entity the field belongs to: company, people, opportunity, task, or note.')
                ->required(),
            'code' => $schema->string()
                ->description('The machine code of the choice-type custom field to add options to, as shown in the custom_fields field list for that entity (e.g. "industry").')
                ->required(),
            'options' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('The option label.')->required(),
                ]))
                ->description('The new options to append to the field.')
                ->required(),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->ownsTeam($user->currentTeam)) {
            return (string) json_encode([
                'error' => 'Only team owners can manage custom field options.',
            ]);
        }

        $entityType = (string) ($request['entity_type'] ?? '');
        $code = (string) ($request['code'] ?? '');

        if ($entityType === '' || $code === '') {
            return (string) json_encode(['error' => 'Both entity_type and code are required to identify the field.']);
        }

        $teamId = $user->currentTeam->getKey();
        $field = $this->resolveOwnedCustomField($teamId, $entityType, $code);

        if (! $field instanceof CustomField) {
            return (string) json_encode(['error' => "No custom field with code \"{$code}\" found on {$entityType}."]);
        }

        if (! in_array($field->type, CreateCustomField::CHOICE_TYPES, true)) {
            return (string) json_encode([
                'error' => "Field type \"{$field->type}\" does not support options. Only select, multi-select, radio, checkbox-list, and toggle-buttons fields can have options added.",
            ]);
        }

        try {
            $validated = CustomFieldDefinitionValidator::forNewOptions($user, $field, [
                'options' => $request['options'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        $optionNames = array_column($validated['options'], 'name');

        $actionData = [
            '_record_id' => $field->getKey(),
            'options' => $validated['options'],
        ];

        $displayData = [
            'title' => 'Add Custom Field Options',
            'summary' => "Add options to \"{$field->name}\": ".implode(', ', $optionNames),
            'fields' => [
                ['label' => 'Field', 'value' => $field->name],
                ['label' => 'New Options', 'value' => implode(', ', $optionNames)],
            ],
        ];

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: AddCustomFieldOptions::class,
            operation: PendingActionOperation::Create,
            entityType: 'custom_field',
            actionData: $actionData,
            displayData: $displayData,
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => 'AddCustomFieldOptions',
            'entity_type' => 'custom_field',
            'operation' => 'create',
            'data' => $pending->action_data,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_PRETTY_PRINT);
    }
}
