<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Models\Concerns\BelongsToTeamCreator;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasTeam;
use App\Models\Concerns\InvalidatesRelatedAiSummaries;
use App\Models\Concerns\LogsCrmActivity;
use App\Observers\TaskObserver;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Relaticle\CustomFields\Models\Concerns\UsesCustomFields;
use Relaticle\CustomFields\Models\Contracts\HasCustomFields;
use Spatie\EloquentSortable\SortableTrait;

/**
 * @property int $id
 * @property Carbon|null $deleted_at
 * @property CreationSource $creation_source
 * @property string $createdBy
 *
 * @method void saveCustomFieldValue(CustomField $field, mixed $value)
 */
#[ObservedBy(TaskObserver::class)]
final class Task extends Model implements HasCustomFields
{
    use BelongsToTeamCreator;
    use HasCreator;

    /** @use HasFactory<TaskFactory> */
    use HasFactory;

    use HasTeam;
    use HasUlids;
    use InvalidatesRelatedAiSummaries;
    use LogsCrmActivity;
    use SoftDeletes;
    use SortableTrait;
    use UsesCustomFields;

    protected $fillable = [
        'user_id',
        'title',
        'creation_source',
    ];

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
     * @var array{order_column_name: 'order_column', sort_when_creating: true}
     */
    public array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    /**
     * @return BelongsToMany<User, $this>
     */
    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @return MorphToMany<Company, $this>
     */
    public function companies(): MorphToMany
    {
        return $this->morphedByMany(Company::class, 'taskable');
    }

    /**
     * @return MorphToMany<Opportunity, $this>
     */
    public function opportunities(): MorphToMany
    {
        return $this->morphedByMany(Opportunity::class, 'taskable');
    }

    /**
     * @return MorphToMany<People, $this>
     */
    public function people(): MorphToMany
    {
        return $this->morphedByMany(People::class, 'taskable');
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function forCompany(Builder $query, string $companyId): void
    {
        $query->whereHas('companies', fn (Builder $q) => $q->where('companies.id', $companyId));
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function forPerson(Builder $query, string $personId): void
    {
        $query->whereHas('people', fn (Builder $q) => $q->where('people.id', $personId));
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function forOpportunity(Builder $query, string $opportunityId): void
    {
        $query->whereHas('opportunities', fn (Builder $q) => $q->where('opportunities.id', $opportunityId));
    }
}
