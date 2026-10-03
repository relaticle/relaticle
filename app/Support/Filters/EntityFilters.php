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
    public function __construct(private User $user) {}

    public static function grammar(CrmEntity $entity): string
    {
        $definitions = self::definitions($entity);
        $named = static fn (FilterKind ...$kinds): array => array_keys(array_filter(
            $definitions,
            static fn (FilterDefinition $definition): bool => in_array($definition->kind, $kinds, true),
        ));
        $relations = array_map(
            static fn (string $name): string => "{$name} (".$definitions[$name]->related?->value.')',
            $named(FilterKind::Relation),
        );

        $sentences = [
            'An object of conditions. Keys starting with $ are keywords: operators ('.implode(', ', CustomFieldFilterSchema::operatorNames()).') and logic ($and and $or take a list of condition objects, $not takes one and also returns records where the inner fields are empty). Other keys are names.',
            'Native fields: '.implode(', ', $named(FilterKind::Text, FilterKind::DateTime, FilterKind::Enum)).'.',
        ];

        if ($relations !== []) {
            $sentences[] = 'Relations take '.implode(', ', FilterDefinition::LINK_OPERATORS).' on record ids, or conditions on the related record: '.implode(', ', $relations).'.';
        }

        if ($named(FilterKind::Members) !== []) {
            $sentences[] = 'Member relations take '.FilterDefinition::MEMBER_OPERAND.': '.implode(', ', $named(FilterKind::Members)).'.';
        }

        foreach ($named(FilterKind::Computed) as $name) {
            $sentences[] = "{$name} takes {$definitions[$name]->operand()}, for example ".CustomFieldFilterSchema::json([$name => $definitions[$name]->example()]).'.';
        }

        return implode(' ', [
            ...$sentences,
            'custom_fields takes an object keyed by custom field code, each value an operator object.',
            self::limits(),
            CustomFieldFilterSchema::valueRules(),
        ]);
    }

    public static function limits(): string
    {
        return 'A filter holds at most '.FilterTree::MAX_CONDITIONS.' conditions, '.FilterTree::MAX_LOGIC_DEPTH.' levels of $and, $or and $not, and '.FilterTree::MAX_HOPS.' levels of relations. A list holds at most '.CustomFieldFilterSchema::MAX_LIST_VALUES.' values.';
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
                'stale_days' => FilterDefinition::computed(StaleDaysFilter::class, ['$gte'], StaleDaysFilter::EXAMPLE, StaleDaysFilter::OPERAND),
            ],
            CrmEntity::Task => [
                'assignees' => FilterDefinition::members(),
                'companies' => FilterDefinition::relation(CrmEntity::Company),
                'people' => FilterDefinition::relation(CrmEntity::People),
                'opportunities' => FilterDefinition::relation(CrmEntity::Opportunity),
                'assigned_to_me' => FilterDefinition::computed(AssignedToMeFilter::class, ['$eq'], AssignedToMeFilter::EXAMPLE, AssignedToMeFilter::OPERAND),
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
