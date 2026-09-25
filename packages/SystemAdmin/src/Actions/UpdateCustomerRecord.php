<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final readonly class UpdateCustomerRecord
{
    private const array PROTECTED_ATTRIBUTES = [
        User::class => ['email', 'email_verified_at', 'password'],
        Workspace::class => ['user_id', 'personal_workspace'],
        Company::class => ['workspace_id'],
        People::class => ['workspace_id'],
        Opportunity::class => ['workspace_id'],
        Task::class => ['workspace_id'],
        Note::class => ['workspace_id'],
    ];

    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(SystemAdministrator $actor, Model $record, array $data): Model
    {
        DB::transaction(function () use ($actor, $record, $data): void {
            $actor = SystemAdministrator::query()->whereKey($actor->getKey())->lockForUpdate()->first();

            abort_unless($actor?->hasVerifiedEmail(), 403);
            abort_unless(array_key_exists($record::class, self::PROTECTED_ATTRIBUTES), 403);

            $record = $record->newQueryWithoutScopes()->whereKey($record->getKey())->lockForUpdate()->firstOrFail();
            $record->fill($data);

            abort_unless(
                $actor->role->canManageCustomerAccess() || ! $record->isDirty(self::PROTECTED_ATTRIBUTES[$record::class]),
                403,
            );

            $record->save();
        });

        return $record->refresh();
    }
}
