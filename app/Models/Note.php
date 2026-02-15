<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreationSource;
use App\Models\Concerns\HasCreator;
use App\Models\Concerns\HasTeam;
use App\Models\Concerns\InvalidatesRelatedAiSummaries;
use App\Observers\NoteObserver;
use Database\Factories\NoteFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
final class Note extends Model implements HasCustomFields
{
    use HasCreator;

    /** @use HasFactory<NoteFactory> */
    use HasFactory;

    use HasTeam;
    use HasUlids;
    use InvalidatesRelatedAiSummaries;
    use SoftDeletes;
    use UsesCustomFields;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
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
}
