<?php

declare(strict_types=1);

namespace App\Queries\Views;

use App\Enums\CrmEntity;
use App\Enums\CustomFields\TaskField;
use App\Enums\CustomFieldType;
use App\Models\CustomField;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CustomFields\WorkspaceCustomFields;
use Illuminate\Support\Collection;

final readonly class BoardCardAttributesQuery
{
    private const string CUSTOM_FIELD_PREFIX = 'custom_fields.';

    private const array DERIVED_FROM_DESCRIPTION = ['description', 'checklist'];

    public function __construct(private WorkspaceCustomFields $customFields) {}

    /**
     * @return list<string>
     */
    public function get(User $user, Workspace $workspace, CrmEntity $entity): array
    {
        if (! $user->belongsToWorkspaceId($workspace->getKey())) {
            return [];
        }

        $showsDescription = $this->showsDescription($workspace, $entity);

        $natives = array_filter(
            $entity->nativeCardAttributes(),
            static fn (string $key): bool => $showsDescription || ! in_array($key, self::DERIVED_FROM_DESCRIPTION, true),
        );

        return [
            ...array_values($natives),
            ...$this->fields($user, $workspace, $entity)->keys()->map(static fn (string $code): string => self::CUSTOM_FIELD_PREFIX.$code)->all(),
        ];
    }

    /**
     * @return Collection<string, CustomField>
     */
    public function fields(User $user, Workspace $workspace, CrmEntity $entity): Collection
    {
        if (! $user->belongsToWorkspaceId($workspace->getKey())) {
            return new Collection;
        }

        return $this->customFields->forEntity($workspace, $entity->value)
            ->filter(static fn (CustomField $field): bool => $field->active
                && ! $field->settings->encrypted
                && ($entity !== CrmEntity::Task || $field->code !== TaskField::DESCRIPTION->value)
                && CustomFieldType::tryFrom($field->type)?->fitsOnCard() === true)
            ->keyBy('code');
    }

    private function showsDescription(Workspace $workspace, CrmEntity $entity): bool
    {
        return $entity === CrmEntity::Task
            && $this->customFields->forEntity($workspace, $entity->value)
                ->contains(static fn (CustomField $field): bool => $field->code === TaskField::DESCRIPTION->value && $field->active);
    }
}
