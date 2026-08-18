<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\CreateCustomField;
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

final class CreateCustomFieldTool implements Tool
{
    use ReportsValidationFailures;
    use WithConversationContext;

    public function name(): string
    {
        return 'CreateCustomFieldTool';
    }

    public function description(): string
    {
        return 'Propose creating a new custom field definition on a CRM entity. Field names and codes must be unique per entity — check ListCustomFieldsTool first. Admin-only — returns an error for non-owners. Returns a proposal for user approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        $allowedTypes = implode(', ', CreateCustomField::ALLOWED_TYPES);

        return [
            'entity_type' => $schema->string()
                ->description('The CRM entity to add the field to: company, people, opportunity, task, or note.')
                ->required(),
            'name' => $schema->string()
                ->description('The display name for the field (e.g. "Industry", "Priority"). Max 50 characters, and must not match an existing field on the same entity.')
                ->required(),
            'type' => $schema->string()
                ->description("The field type. Allowed: {$allowedTypes}. NOT allowed: file-upload, record, rich-editor, markdown-editor, currency.")
                ->required(),
            'code' => $schema->string()
                ->description('Optional machine-readable code (snake_case). Auto-generated from name if omitted.'),
            'options' => $schema->array()
                ->items($schema->object([
                    'name' => $schema->string()->description('The option label.')->required(),
                ]))
                ->description('Required for choice types (select, multi-select, radio, checkbox-list, toggle-buttons). Must not be provided for other types.'),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        if (! $user->ownsTeam($user->currentTeam)) {
            return (string) json_encode([
                'error' => 'Only team owners can create custom field definitions. I can guide you to the Custom Fields settings page if you want to ask your team owner to do this.',
            ]);
        }

        try {
            $validated = CustomFieldDefinitionValidator::forCreate($user, [
                'entity_type' => $request['entity_type'] ?? null,
                'name' => $request['name'] ?? null,
                'type' => $request['type'] ?? null,
                'code' => $request['code'] ?? null,
                'options' => $request['options'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            return $this->validationError($exception);
        }

        $entityType = (string) $validated['entity_type'];
        $name = (string) $validated['name'];
        $type = (string) $validated['type'];
        $code = (string) ($validated['code'] ?? '');
        $optionNames = array_column(is_array($validated['options'] ?? null) ? $validated['options'] : [], 'name');

        $actionData = array_filter([
            'entity_type' => $entityType,
            'name' => $name,
            'type' => $type,
            'code' => $code !== '' ? $code : null,
            'options' => $optionNames !== [] ? array_map(static fn (string $option): array => ['name' => $option], $optionNames) : null,
        ], fn (mixed $value): bool => $value !== null);

        $displayFields = [
            ['label' => 'Entity', 'value' => $entityType],
            ['label' => 'Name', 'value' => $name],
            ['label' => 'Type', 'value' => $type],
        ];

        if ($code !== '') {
            $displayFields[] = ['label' => 'Code', 'value' => $code];
        }

        if ($optionNames !== []) {
            $displayFields[] = ['label' => 'Options', 'value' => implode(', ', $optionNames)];
        }

        $optionsSummary = $optionNames !== [] ? ' with options: '.implode(', ', $optionNames) : '';

        $displayData = [
            'title' => 'Create Custom Field',
            'summary' => "Create \"{$name}\" ({$type}) on {$entityType}{$optionsSummary}",
            'fields' => $displayFields,
        ];

        $pending = resolve(PendingActionService::class)->createProposal(
            user: $user,
            conversationId: $this->resolveConversationId(),
            actionClass: CreateCustomField::class,
            operation: PendingActionOperation::Create,
            entityType: 'custom_field',
            actionData: $actionData,
            displayData: $displayData,
        );

        return (string) json_encode([
            'type' => 'pending_action',
            'pending_action_id' => $pending->id,
            'action' => 'CreateCustomField',
            'entity_type' => 'custom_field',
            'operation' => 'create',
            'data' => $pending->action_data,
            'display' => $pending->display_data,
            'meta' => ['agent_should_stop' => true],
        ], JSON_PRETTY_PRINT);
    }
}
