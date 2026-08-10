<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\CustomField;

use App\Actions\CustomFields\CreateCustomField;
use App\Models\CustomField;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Tools\Concerns\WithConversationContext;
use Relaticle\CustomFields\Models\Scopes\CustomFieldsActivableScope;

final class CreateCustomFieldTool implements Tool
{
    use WithConversationContext;

    public function name(): string
    {
        return 'CreateCustomFieldTool';
    }

    public function description(): string
    {
        return 'Propose creating a new custom field definition on a CRM entity. Admin-only — returns an error for non-owners. Returns a proposal for user approval.';
    }

    public function schema(JsonSchema $schema): array
    {
        $allowedTypes = implode(', ', CreateCustomField::ALLOWED_TYPES);

        return [
            'entity_type' => $schema->string()
                ->description('The CRM entity to add the field to: company, people, opportunity, task, or note.')
                ->required(),
            'name' => $schema->string()
                ->description('The display name for the field (e.g. "Industry", "Priority").')
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

        $type = (string) ($request['type'] ?? '');

        if (! in_array($type, CreateCustomField::ALLOWED_TYPES, true)) {
            return (string) json_encode([
                'error' => "Field type \"{$type}\" is not supported via chat. Allowed types: ".implode(', ', CreateCustomField::ALLOWED_TYPES).'.',
            ]);
        }

        $isChoiceType = in_array($type, CreateCustomField::CHOICE_TYPES, true);

        $options = is_array($request['options'] ?? null) ? $request['options'] : [];

        if ($isChoiceType && $options === []) {
            return (string) json_encode([
                'error' => "Field type \"{$type}\" requires at least one option. Please provide an options array with at least one {\"name\": \"...\"} entry.",
            ]);
        }

        if (! $isChoiceType && $options !== []) {
            return (string) json_encode([
                'error' => "Field type \"{$type}\" does not support options.",
            ]);
        }

        $entityType = (string) ($request['entity_type'] ?? '');

        if (! in_array($entityType, CreateCustomField::VALID_ENTITY_TYPES, true)) {
            return (string) json_encode([
                'error' => 'Invalid entity type "'.htmlspecialchars($entityType, ENT_QUOTES, 'UTF-8').'". Must be one of: '.implode(', ', CreateCustomField::VALID_ENTITY_TYPES).'.',
            ]);
        }

        $maxFields = (int) config('chat.max_custom_fields_per_entity', 50);
        $teamId = $user->currentTeam->getKey();
        $existingCount = CustomField::query()
            ->withoutGlobalScope(CustomFieldsActivableScope::class)
            ->where('tenant_id', $teamId)
            ->where('entity_type', $entityType)
            ->count();

        if ($existingCount >= $maxFields) {
            return (string) json_encode([
                'error' => "Cannot create more than {$maxFields} custom fields for entity type \"{$entityType}\".",
            ]);
        }

        if ($isChoiceType) {
            $maxOptions = (int) config('chat.max_field_options', 50);
            if (count($options) > $maxOptions) {
                return (string) json_encode([
                    'error' => "Too many options — at most {$maxOptions} per field.",
                ]);
            }
        }

        $name = (string) ($request['name'] ?? '');
        $code = (string) ($request['code'] ?? '');

        $actionData = array_filter([
            'entity_type' => $entityType,
            'name' => $name,
            'type' => $type,
            'code' => $code !== '' ? $code : null,
            'options' => $options !== [] ? $options : null,
        ], fn (mixed $v): bool => $v !== null);

        $optionsSummary = $options !== []
            ? ' with options: '.implode(', ', array_map(static fn (mixed $o): string => is_array($o) ? (string) ($o['name'] ?? '') : (string) $o, $options))
            : '';

        $displayFields = [
            ['label' => 'Entity', 'value' => $entityType],
            ['label' => 'Name', 'value' => $name],
            ['label' => 'Type', 'value' => $type],
        ];

        if ($code !== '') {
            $displayFields[] = ['label' => 'Code', 'value' => $code];
        }

        if ($options !== []) {
            $displayFields[] = [
                'label' => 'Options',
                'value' => implode(', ', array_map(static fn (mixed $o): string => is_array($o) ? (string) ($o['name'] ?? '') : (string) $o, $options)),
            ];
        }

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
