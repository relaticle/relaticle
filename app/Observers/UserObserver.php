<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class UserObserver
{
    /**
     * Clear the deleted user from any chat participation that outlives them.
     *
     * Conversations in teams the user merely belonged to survive the purge, and
     * participant_id is a polymorphic key, so no foreign key can null itself on
     * delete the way agent_conversations.user_id used to. This lives on the model
     * rather than in DeleteUser so that every delete path is covered — the
     * SystemAdmin panel deletes users through plain Eloquent, not the
     * DeletesUsers contract.
     */
    public function deleting(User $user): void
    {
        $participant = [
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
        ];

        $anonymised = [
            'participant_type' => null,
            'participant_id' => null,
        ];

        DB::table('agent_conversations')->where($participant)->update($anonymised);
        DB::table('agent_conversation_messages')->where($participant)->update($anonymised);
    }
}
