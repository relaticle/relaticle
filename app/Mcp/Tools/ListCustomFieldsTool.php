<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Enums\CrmEntity;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Relaticle\CustomFields\Services\ValidationService;

#[Title('List Custom Fields')]
#[Description('List workspace custom-field definitions, including inactive fields and configured option labels. Use get-crm-schema for the active write schema.')]
final class ListCustomFieldsTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;

    private const int MAX_PER_PAGE = 25;

    private const int MAX_PAGE = 1_000_000;

    public function __construct(
        private readonly ValidationService $validation,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()->description('Optional entity type: company, people, opportunity, task, or note.'),
            'active' => $schema->boolean()->description('Optional active-status filter. Omit to include both active and inactive fields.'),
            'per_page' => $schema->integer()->description('Results per page (default 25, max 25).')->default(self::MAX_PER_PAGE),
            'page' => $schema->integer()->description('Page number (default 1, max 1,000,000).')->default(1),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'items' => $schema->array()->items($schema->object())->required(),
            'page' => $schema->integer()->required(),
            'per_page' => $schema->integer()->required(),
            'total' => $schema->integer()->required(),
            'has_more' => $schema->boolean()->required(),
            'next_page' => $schema->integer()->nullable()->required(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        $validated = $request->validate([
            'entity_type' => ['sometimes', 'string', Rule::in(CrmEntity::morphAliases())],
            'active' => ['sometimes', 'boolean'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
            'page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PAGE],
        ]);

        /** @var User $user */
        $user = $request->user();
        $perPage = (int) ($validated['per_page'] ?? self::MAX_PER_PAGE);
        $page = (int) ($validated['page'] ?? 1);

        $fields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $user->currentTeam->getKey())
            ->when(
                isset($validated['entity_type']),
                fn (Builder $query): Builder => $query->where('entity_type', $validated['entity_type']),
            )
            ->when(
                array_key_exists('active', $validated),
                fn (Builder $query): Builder => $query->where('active', $validated['active']),
            )
            // The package's options() relation eager-loads customField; without this
            // the parent rows we already hold are re-queried and re-hydrated per option.
            ->with(['options' => fn (Relation $query): Relation => $query
                ->without('customField')
                ->withoutGlobalScopes()
                ->orderBy('sort_order')])
            ->orderBy('entity_type')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $items = $fields->getCollection()->map(fn (CustomField $field): array => [
            'id' => $field->id,
            'entity_type' => $field->entity_type,
            'code' => $field->code,
            'name' => $field->name,
            'type' => $field->type,
            'active' => $field->active,
            'system_defined' => $field->system_defined,
            'required' => $this->validation->isRequired($field),
            'options' => $field->options->map(fn (CustomFieldOption $option): array => [
                'id' => $option->id,
                'label' => $option->name,
            ])->values()->all(),
        ])->values()->all();

        return Response::structured([
            'items' => $items,
            'page' => $fields->currentPage(),
            'per_page' => $fields->perPage(),
            'total' => $fields->total(),
            'has_more' => $fields->hasMorePages(),
            'next_page' => $fields->hasMorePages() ? $fields->currentPage() + 1 : null,
        ]);
    }
}
