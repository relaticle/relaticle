<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Models\Concerns\BelongsToTeamCreator;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasTeam;
use App\Models\Concerns\InvalidatesRelatedAiSummaries;
use App\Observers\NoteObserver;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;

/**
 * @property Carbon|null $deleted_at
 * @property CreationSource $creation_source
 */
#[ObservedBy(NoteObserver::class)]
#[Fillable([
    'creation_source',
])]
final class Note extends Model implements HasCustomFields
{
    use BelongsToTeamCreator;
    use HasCreator;

    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use HasTeam;
    use HasUlids;
    use InvalidatesRelatedAiSummaries;
    use SoftDeletes;
    use UsesCustomFields;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'creation_source' => CreationSource::WEB,
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'creation_source' => CreationSource::class,
        ];
    }

    /**
     * @return MorphToMany<Company, $this>
     */
    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'noteable');
    }

    /**
     * @return MorphToMany<People, $this>
     */
    public function people(): MorphToMany
    {
        return $this->morphedByMany(People::class, 'noteable');
    }

    /**
     * @return MorphToMany<Opportunity, $this>
     */
    public function opportunities(): MorphToMany
    {
        return $this->morphedByMany(Opportunity::class, 'noteable');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function forNotableType(Builder $query, string $type): void
    {
        $relationMap = [
            'company' => 'companies',
            'people' => 'people',
            'opportunity' => 'opportunities',
        ];

        $relation = $relationMap[$type] ?? null;

        if ($relation) {
            $query->whereHas($relation);
        }
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function forNotableId(Builder $query, string $id): void
    {
        $query->where(function (Builder $q) use ($id): void {
            $q->whereHas('companies', fn (Builder $sub) => $sub->where('noteables.noteable_id', $id))
                ->orWhereHas('people', fn (Builder $sub) => $sub->where('noteables.noteable_id', $id))
                ->orWhereHas('opportunities', fn (Builder $sub) => $sub->where('noteables.noteable_id', $id));
        });
    }
}
