<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Enums\CreationSource;
use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class CreateNote
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, array $data, CreationSource $source = CreationSource::WEB): Note
    {
        abort_unless($user->can('create', Note::class), 403);

        $data['creation_source'] = $source;
        $data['creator_id'] = $user->getKey();
        $data['team_id'] = $user->currentTeam->getKey();

        return DB::transaction(fn (): Note => Note::query()->create($data));
    }
}
