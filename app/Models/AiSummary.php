<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasWorkspace;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'workspace_id',
    'summarizable_type',
    'summarizable_id',
    'summary',
    'input_hash',
    'model_used',
    'prompt_tokens',
    'completion_tokens',
])]
final class AiSummary extends Model
{
    /** @use HasFactory<Factory<self>> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

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
