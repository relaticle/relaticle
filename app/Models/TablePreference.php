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
 * @property ?TableStateData $own_state
 * @property string $user_id
 * @property ?string $active_view_id
 * @property ?list<string> $view_order
 * @property ?string $applied_view_hash
 */
#[ScopedBy(WorkspaceScope::class)]
#[Fillable(['workspace_id', 'user_id', 'entity', 'state', 'own_state', 'active_view_id', 'view_order', 'applied_view_hash'])]
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
            'own_state' => TableStateData::class,
            'view_order' => 'array',
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
