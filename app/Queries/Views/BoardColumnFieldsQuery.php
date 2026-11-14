<?php

declare(strict_types=1);

namespace App\Queries\Views;

use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CustomFields\WorkspaceCustomFields;
use Illuminate\Support\Collection;

final readonly class BoardColumnFieldsQuery
{
    public function __construct(private WorkspaceCustomFields $customFields) {}

    /**
     * @return Collection<string, CustomField>
     */
    public function get(User $user, Workspace $workspace, CrmEntity $entity): Collection
    {
        $default = $entity->boardFieldCode();

        if ($default === null || ! $user->belongsToWorkspaceId($workspace->getKey())) {
            return new Collection;
        }

        return $this->customFields->forEntity($workspace, $entity->value)
            ->filter(static fn (CustomField $field): bool => $field->active
                && ! $field->settings->encrypted
                && CustomFieldType::tryFrom($field->type)?->makesBoardColumns() === true
                && $field->options->isNotEmpty())
            ->sortBy(static fn (CustomField $field): int => $field->code === $default ? 0 : 1)
            ->keyBy('code');
    }

    /**
     * @return list<string>|null
     */
    public function optionIds(User $user, Workspace $workspace, CrmEntity $entity, ?string $code): ?array
    {
        $field = $code === null ? null : $this->get($user, $workspace, $entity)->get($code);

        return $field instanceof CustomField
            ? array_values($field->options->map(static fn (CustomFieldOption $option): string => (string) $option->getKey())->all())
            : null;
    }
}
