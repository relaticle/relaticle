<?php

declare(strict_types=1);

namespace Relaticle\Chat\Services\Tools;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\Chat\Support\ProposalPayload;

/**
 * Decides whether a `$ref:<pending_action_id>` a write tool was handed may stand
 * in for a real record id.
 *
 * A reference is only ever legal within the turn that created its target: the
 * assistant proposes the company, reads back its proposal id, and references it
 * from the contact it proposes next. Anything looser (another conversation,
 * another turn, an already-decided proposal, a proposal for the wrong entity)
 * is rejected here as a tool error the model can correct, exactly as a
 * hallucinated record id is today.
 */
final readonly class PlanReferenceValidator
{
    /**
     * @param  class-string<Model>  $expectedModel
     * @return string|null An error message, or null when the reference is usable.
     */
    public function error(
        User $user,
        mixed $value,
        string $expectedModel,
        ?string $conversationId,
        ?string $turnId,
    ): ?string {
        $target = PlanReference::target($value);

        if ($target === null) {
            return null;
        }

        if ($conversationId === null || $turnId === null) {
            return 'References to earlier steps are only available inside a chat turn.';
        }

        $referenced = PendingAction::query()
            ->whereKey($target)
            ->where('team_id', $user->currentTeam->getKey())
            ->where('user_id', $user->getKey())
            ->where('conversation_id', $conversationId)
            ->where('turn_id', $turnId)
            ->first();

        if (! $referenced instanceof PendingAction) {
            return "Unknown step reference `{$value}`. Reference a proposal you created earlier in THIS turn, using the pending_action_id its tool result returned.";
        }

        if ($referenced->operation !== PendingActionOperation::Create) {
            return "Step reference `{$value}` points at a {$referenced->operation->value} proposal. Only a create step produces a new record to reference.";
        }

        if ($referenced->status !== PendingActionStatus::Pending) {
            return "Step reference `{$value}` points at a proposal that is already {$referenced->status->value}. Use the record's real id instead.";
        }

        if (ProposalPayload::from($referenced)->isBatch) {
            return "Step reference `{$value}` points at a multi-record proposal, so it is ambiguous. Propose that record in its own tool call to reference it.";
        }

        $referencedModel = Relation::getMorphedModel($referenced->entity_type);

        if ($referencedModel !== $expectedModel) {
            $expected = class_basename($expectedModel);

            return "Step reference `{$value}` points at a {$referenced->entity_type} proposal, but a {$expected} is required here.";
        }

        return null;
    }
}
