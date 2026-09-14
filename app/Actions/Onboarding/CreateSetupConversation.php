<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Features\SetupConversation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AgentConversation;

final readonly class CreateSetupConversation
{
    /**
     * The thread the owner lands on after signup. It is created empty: the
     * first message is a real streamed turn the owner watches arrive, started
     * by StartSetupGreeting when they open it.
     */
    public function execute(Workspace $workspace): ?string
    {
        if (! Feature::active(SetupConversation::class)) {
            return null;
        }

        if (! $workspace->isPersonalWorkspace()) {
            return null;
        }

        $owner = $workspace->owner;

        if (! $owner instanceof User) {
            return null;
        }

        if ($workspace->setupConversation()->exists()) {
            return null;
        }

        $conversationId = (string) Str::uuid7();
        $now = now();

        try {
            DB::table('agent_conversations')->insert([
                'id' => $conversationId,
                'participant_type' => $owner->getMorphClass(),
                'participant_id' => (string) $owner->getKey(),
                'workspace_id' => $workspace->getKey(),
                'title' => __('onboarding/setup.title'),
                'purpose' => AgentConversation::PURPOSE_SETUP,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existingId = $workspace->setupConversation()->value('id');

            return is_string($existingId) ? $existingId : null;
        }

        return $conversationId;
    }
}
