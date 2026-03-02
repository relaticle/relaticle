<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Models\Note;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UpdateNote
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Note $note, array $data): Note
    {
        abort_unless($user->can('update', $note), 403);

        return DB::transaction(function () use ($note, $data): Note {
            $note->update($data);

            return $note->refresh();
        });
    }
}
