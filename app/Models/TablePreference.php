<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\TableStateData;
use App\Enums\CrmEntity;
use App\Models\Concerns\HasWorkspace;
use App\Models\Scopes\WorkspaceScope;
use Database\Factories\TablePreferenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CrmEntity $entity
 * @property TableStateData $state
 * @property string $user_id
 * @property ?string $active_layout_id
 * @property ?string $default_layout_id
 * @property ?string $applied_layout_hash
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'entity', 'state', 'active_layout_id', 'default_layout_id', 'applied_layout_hash'])]
final class TablePreference extends Model
{
    /** @use HasFactory<TablePreferenceFactory> */
    use HasFactory;

    use HasUlids;
    use HasWorkspace;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entity' => CrmEntity::class,
            'state' => TableStateData::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
