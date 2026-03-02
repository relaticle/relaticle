<?php

declare(strict_types=1);

namespace App\Actions\People;

use App\Enums\CreationSource;
use App\Models\People;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreatePeople
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): People
    {
        abort_unless($user->can('create', People::class), 403);

        $data['creation_source'] = $source;
        $data['creator_id'] = $user->getKey();
        $data['team_id'] = $user->currentTeam->getKey();

        return DB::transaction(fn (): People => People::query()->create($data));
    }
}
