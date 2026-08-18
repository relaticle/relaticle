<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Filament resolves a record route against the resource's base query, which
 * excludes soft-deleted rows. Support staff need the opposite: a trashed record
 * is exactly the one worth inspecting, and every relation link pointing at it
 * would otherwise 404. Only route binding is widened, not the list table.
 */
trait ResolvesTrashedRecords
{
    /**
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }
}
