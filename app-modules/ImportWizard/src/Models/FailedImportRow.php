<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $import_id
 * @property string $team_id
 * @property array<string, mixed> $data
 * @property string|null $validation_error
 */
final class FailedImportRow extends Model
{
    use HasUlids;
    use MassPrunable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    /** @return BelongsTo<Import, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<=', now()->subMonth());
    }
}
