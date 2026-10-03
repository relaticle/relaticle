<?php

declare(strict_types=1);

namespace App\Support\Filters;

use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\FilterKind;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Models\User;
use Spatie\QueryBuilder\AllowedFilter;

final readonly class EntityFilters
{
    private const string GRAMMAR = 'An object of conditions. Keys starting with $ are keywords: operators ($eq, $gt, $gte, $lt, $lte, $contains, $in, $not_in, $has_any, $has_none, $is_empty) and logic ($and and $or take a list of condition objects, $not takes one and also returns records where the inner fields are empty). Other keys are names: native fields (name, title, created_at, updated_at, creation_source), relations (company, contact, people, opportunities, companies, creator, accountOwner, assignees), computed filters (stale_days on opportunities takes {"$gte": <days without activity>}, assigned_to_me on tasks takes {"$eq": true}) and custom_fields, an object keyed by custom field code. Each field takes an operator object such as {"$gte": "2026-10-01"}. A relation takes $in, $not_in or $is_empty on record ids, or conditions on the related record.';

    public function __construct(private User $user) {}

    public static function grammar(): string
    {
        return self::GRAMMAR.' '.CustomFieldFilterSchema::valueRules();
    }

    /**
     * @return array<string, mixed>
     */
    public static function example(CrmEntity $entity): array
    {
        return [
            $entity->titleColumn() => ['$contains' => 'Acme'],
            '$or' => [
                ['creation_source' => ['$eq' => CreationSource::API->value]],
                ['created_at' => ['$gte' => '2026-01-01']],
            ],
        ];
    }

    /**
     * @return array<string, FilterDefinition>
     */
    public static function definitions(CrmEntity $entity): array
    {
        $common = [
            $entity->titleColumn() => FilterDefinition::text(),
            'created_at' => FilterDefinition::dateTime(),
            'updated_at' => FilterDefinition::dateTime(),
            'creation_source' => FilterDefinition::enum(CreationSource::class),
            'creator' => FilterDefinition::members(),
        ];

        return $common + match ($entity) {
            CrmEntity::Company => [
                'accountOwner' => FilterDefinition::members(),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
            ],
            CrmEntity::People => [
                'company' => FilterDefinition::relation(CrmEntity::Company),
            ],
            CrmEntity::Opportunity => [
                'company' => FilterDefinition::relation(CrmEntity::Company),
                'contact' => FilterDefinition::relation(CrmEntity::People),
                'stale_days' => FilterDefinition::computed(StaleDaysFilter::class, ['$gte']),
            ],
            CrmEntity::Task => [
                'assignees' => FilterDefinition::members(),
                'companies' => FilterDefinition::relation(CrmEntity::Company),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
                'assigned_to_me' => FilterDefinition::computed(AssignedToMeFilter::class, ['$eq']),
            ],
            CrmEntity::Note => [
                'companies' => FilterDefinition::relation(CrmEntity::Company),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
            ],
        };
    }

    /**
     * @return list<AllowedFilter>
     */
    public function for(CrmEntity $entity): array
    {
        $filters = [];

        foreach (self::definitions($entity) as $name => $definition) {
            $filters[] = TreeAllowedFilter::custom($name, match ($definition->kind) {
                FilterKind::Text, FilterKind::DateTime, FilterKind::Enum => new NativeFilter($definition),
                FilterKind::Members, FilterKind::Relation => new RelationFilter($definition, $this, $this->user),
                FilterKind::Computed => new ($definition->filterClass)($this->user),
            });
        }

        $filters[] = TreeAllowedFilter::custom('custom_fields', new CustomFieldFilter($entity->value, $this->user));

        foreach (LogicFilter::KEYWORDS as $keyword) {
            $filters[] = TreeAllowedFilter::custom($keyword, new LogicFilter($keyword, $entity, $this, $this->user));
        }

        return $filters;
    }
}
