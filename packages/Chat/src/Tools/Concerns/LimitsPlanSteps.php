<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Concerns;

use Relaticle\Chat\Models\PendingAction;

/**
 * Caps how many proposals one turn may chain into a single plan card.
 *
 * The cap is enforced here rather than stated in the prompt, for the same reason
 * the batch size is: a limit the model merely knows about is a limit it can talk
 * itself out of. Hitting it returns a tool error, so the assistant asks for
 * approval of what it has instead of building an unreviewable card.
 */
trait LimitsPlanSteps
{
    protected function planStepLimitError(): ?string
    {
        $conversationId = $this->resolveConversationId();
        $turnId = $this->resolveTurnId();

        if ($conversationId === null || $turnId === null) {
            return null;
        }

        $maxSteps = (int) config('chat.max_plan_steps', 6);

        $steps = PendingAction::query()
            ->where('conversation_id', $conversationId)
            ->where('turn_id', $turnId)
            ->pending()
            ->count();

        if ($steps < $maxSteps) {
            return null;
        }

        return "This turn already proposes {$maxSteps} steps, the maximum for one approval."
            .' Stop here and ask the user to review these; the rest can follow once they approve.';
    }
}
