<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Throwable;

/**
 * Filament's delete actions call `$record->delete()` on the model, which is the
 * wrong entry point for a user or a team. Both are owned by a Jetstream deleter
 * that runs side effects the model knows nothing about: deleting a user must
 * also delete the teams it owns, and deleting a team must first cancel its
 * Stripe subscription. Skipping them leaves ownerless workspaces (`teams.user_id`
 * carries no foreign key) and subscriptions that keep billing a workspace nobody
 * can reach.
 *
 * These factories keep Filament's per-record failure reporting, so a partially
 * failed bulk delete still reports "deleted 3 of 5" rather than claiming success.
 */
final class SafeDelete
{
    /**
     * @param  Closure(covariant Model): void  $delete
     */
    public static function action(Closure $delete): DeleteAction
    {
        return DeleteAction::make()
            ->using(function (Model $record) use ($delete): bool {
                $delete($record);

                return true;
            });
    }

    /**
     * @param  Closure(covariant Model): void  $delete
     */
    public static function bulkAction(Closure $delete): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            // Without this Filament may issue one mass `DELETE` query and never
            // hydrate a model, which would bypass the deleter entirely.
            ->fetchSelectedRecords()
            ->using(function (DeleteBulkAction $action, EloquentCollection|Collection|LazyCollection $records) use ($delete): void {
                $isFirstException = true;

                $records->each(function (Model $record) use ($action, $delete, &$isFirstException): void {
                    try {
                        $delete($record);
                    } catch (Throwable $exception) {
                        $action->reportBulkProcessingFailure();

                        if ($isFirstException) {
                            report($exception);

                            $isFirstException = false;
                        }
                    }
                });
            });
    }
}
