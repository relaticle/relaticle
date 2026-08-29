<?php

declare(strict_types=1);

namespace App\Actions\Note;

use App\Models\Note;
use App\Models\User;
use App\Support\CrmRelationshipSync;
use App\Support\TenantFkValidator;
use Illuminate\Support\Facades\DB;

final readonly class DetachNoteRelationships
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Note $note, array $data): Note
    {
        abort_unless($user->can('update', $note), 403);
        abort_unless($note->team_id === $user->current_team_id, 403);

        TenantFkValidator::assertOwnedMany($user, $data, CrmRelationshipSync::OWNED_MODELS);

        DB::transaction(static function () use ($note, $data): void {
            CrmRelationshipSync::detach($note, $data);
        });

        return $note->refresh();
    }
}
