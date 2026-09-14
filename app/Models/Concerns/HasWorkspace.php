<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $workspace_id
 */
trait HasWorkspace
{
    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
