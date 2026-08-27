<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place the `*_ids` payload vocabulary is mapped, both to the models a
 * tenant-ownership check needs and to the relations the attach/detach actions
 * drive. A new linkable entity is added here once instead of in every
 * attach and detach action.
 */
final readonly class CrmRelationshipSync
{
    /**
     * The tenant-owned CRM entities every relationship action validates.
     *
     * @var array<string, class-string<Model>>
     */
    public const array OWNED_MODELS = [
        'company_ids' => Company::class,
        'people_ids' => People::class,
        'opportunity_ids' => Opportunity::class,
    ];

    /**
     * Payload key to relation name. `assignee_ids` only ever reaches a Task:
     * the note tools neither declare nor validate it.
     *
     * @var array<string, string>
     */
    private const array RELATIONS = [
        'company_ids' => 'companies',
        'people_ids' => 'people',
        'opportunity_ids' => 'opportunities',
        'assignee_ids' => 'assignees',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, list<mixed>> newly attached ids, keyed by payload key
     */
    public static function attach(Model $model, array $data): array
    {
        $attached = [];

        foreach (self::presentKeys($data) as $key => $relation) {
            /** @var array<string, list<mixed>> $changes */
            $changes = $model->{$relation}()->syncWithoutDetaching($data[$key]);
            $attached[$key] = $changes['attached'];
        }

        return $attached;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function detach(Model $model, array $data): void
    {
        foreach (self::presentKeys($data) as $key => $relation) {
            $model->{$relation}()->detach($data[$key]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private static function presentKeys(array $data): array
    {
        return array_filter(
            self::RELATIONS,
            static fn (string $key): bool => array_key_exists($key, $data),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
