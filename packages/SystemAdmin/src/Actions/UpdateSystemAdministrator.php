<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Relaticle\SystemAdmin\Rules\KeepsALastSuperAdministrator;

final readonly class UpdateSystemAdministrator
{
    /** @param array<string, mixed> $data */
    public function execute(SystemAdministrator $user, SystemAdministrator $record, array $data): SystemAdministrator
    {
        return DB::transaction(function () use ($user, $record, $data): SystemAdministrator {
            $administrators = SystemAdministrator::query()->orderBy('id')->lockForUpdate()->get();
            $actor = $administrators->find($user->getKey());
            $administrator = $administrators->find($record->getKey());

            abort_unless($actor instanceof SystemAdministrator, 403);
            abort_unless($administrator instanceof SystemAdministrator, 404);
            Gate::forUser($actor)->authorize('update', $administrator);

            Validator::make($data, [
                'role' => ['sometimes', 'required', Rule::enum(SystemAdministratorRole::class), new KeepsALastSuperAdministrator($administrator)],
            ])->validate();

            $administrator->update($data);

            return $record->refresh();
        });
    }
}
