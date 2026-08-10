<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;

final readonly class DeleteEmailDraftAction
{
    /**
     * User-initiated delete: a missing/foreign draft id is an authorization
     * failure and aborts loudly.
     */
    public function execute(User $user, string $draftId): void
    {
        $draft = $this->findOwnedDraft($user, $draftId);

        abort_if(! $draft instanceof Email, 403);

        $this->delete($draft);
    }

    /**
     * Best-effort cleanup for internal callers (e.g. post-send, where two
     * tabs open on the same draft — or a retried request — can mean the
     * draft row is already gone by the time this runs). The desired end
     * state, "no draft row", already holds in that case, so a missing draft
     * is success here, not a 403 — unlike {@see self::execute()}.
     */
    public function executeIfExists(User $user, string $draftId): void
    {
        $draft = $this->findOwnedDraft($user, $draftId);

        if (! $draft instanceof Email) {
            return;
        }

        $this->delete($draft);
    }

    private function findOwnedDraft(User $user, string $draftId): ?Email
    {
        return Email::query()
            ->where('user_id', $user->getKey())
            ->where('team_id', $user->current_team_id)
            ->where('status', EmailStatus::DRAFT)
            ->whereKey($draftId)
            ->first();
    }

    private function delete(Email $draft): void
    {
        DB::transaction(function () use ($draft): void {
            $draft->body()->delete();
            $draft->participants()->delete();
            // Email uses SoftDeletes — a plain delete() would leave a husk row
            // (subject/snippet intact) behind indefinitely. A deleted draft
            // must actually be gone.
            $draft->forceDelete();
        });
    }
}
