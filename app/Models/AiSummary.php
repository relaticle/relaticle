<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasTeam;
use App\Observers\AiSummaryObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[ObservedBy(AiSummaryObserver::class)]
#[Fillable([
    'team_id',
    'summarizable_type',
    'summarizable_id',
    'summary',
    'model_used',
    'prompt_tokens',
    'completion_tokens',
])]
final class AiSummary extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasTeam;
    use HasUlids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function summarizable(): MorphTo
    {
        return $this->morphTo();
    }
}
