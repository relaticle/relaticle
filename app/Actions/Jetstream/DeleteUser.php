<?php

declare(strict_types=1);

namespace App\Actions\Jetstream;

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Jetstream\Contracts\DeletesTeams;
use Laravel\Jetstream\Contracts\DeletesUsers;

final readonly class DeleteUser implements DeletesUsers
{
    /**
     * Create a new action instance.
     */
    public function __construct(private DeletesTeams $deletesTeams) {}

    /**
     * Delete the given user.
     */
    public function delete(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->deleteTeams($user);
            $this->anonymiseChatParticipation($user);
            $user->deleteProfilePhoto();
            $user->tokens->each->delete();
            $user->delete();
        });
    }

    /**
     * Conversations in teams the user merely belonged to survive the purge, and
     * participant_id carries no foreign key that could null itself on delete.
     */
    private function anonymiseChatParticipation(User $user): void
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

    /**
     * Delete the teams and team associations attached to the user.
     */
    private function deleteTeams(User $user): void
    {
        $user->teams()->detach();

        $user->ownedTeams->each(function (Model $team): void {
            /** @var Team $team */
            $this->deletesTeams->delete($team);
        });
    }
}
