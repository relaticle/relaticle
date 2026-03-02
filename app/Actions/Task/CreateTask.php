<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Enums\CreationSource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateTask
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Task
    {
        abort_unless($user->can('create', Task::class), 403);

        $data['creation_source'] = $source;
        $data['creator_id'] = $user->getKey();
        $data['team_id'] = $user->currentTeam->getKey();

        return DB::transaction(fn (): Task => Task::query()->create($data));
    }
}
