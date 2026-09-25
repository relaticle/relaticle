<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class DeleteSystemAdministrator
{
    public function execute(SystemAdministrator $user, SystemAdministrator $record): void
    {
        DB::transaction(function () use ($user, $record): void {
            $administrators = SystemAdministrator::query()->orderBy('id')->lockForUpdate()->get();
            $actor = $administrators->find($user->getKey());
            $administrator = $administrators->find($record->getKey());

            abort_unless($actor instanceof SystemAdministrator, 403);
            abort_unless($administrator instanceof SystemAdministrator, 404);
            Gate::forUser($actor)->authorize('delete', $administrator);

            $administrator->delete();
        });
    }
}
