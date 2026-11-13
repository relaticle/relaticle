<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Notifications\RefreshNotificationBell;
use App\Models\ActivityLog\Activity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;

final readonly class DecideEmailAccessRequest
{
    public function __construct(private RefreshNotificationBell $refreshBell) {}

    public function execute(EmailAccessRequest $request, EmailAccessRequestStatus $outcome, ?User $actor): bool
    {
        return DB::transaction(function () use ($request, $outcome, $actor): bool {
            $decidedAt = now();
            $decidedBy = $actor?->getKey();

            $decided = EmailAccessRequest::query()
                ->whereKey($request->getKey())
                ->when(
                    $outcome === EmailAccessRequestStatus::EXPIRED,
                    fn (Builder $pending): Builder => $pending->overdue(),
                    fn (Builder $pending): Builder => $pending->open(),
                )
                ->update([
                    'status' => $outcome->value,
                    'decided_at' => $decidedAt,
                    'decided_by' => $decidedBy,
                ]);

            if ($decided === 0) {
                return false;
            }

            $request->forceFill([
                'status' => $outcome,
                'decided_at' => $decidedAt,
                'decided_by' => $decidedBy,
            ])->syncOriginal();

            $this->logOutcome($request, $outcome, $actor);
            $this->refreshOwnerBell($request);

            return true;
        });
    }

    private function logOutcome(EmailAccessRequest $request, EmailAccessRequestStatus $outcome, ?User $actor): void
    {
        $activity = activity(Activity::EMAIL_ACCESS_LOG)->performedOn($request)->event($outcome->value);

        if ($actor instanceof User) {
            $activity->causedBy($actor);
        } else {
            $activity->causedByAnonymous();
        }

        $activity->log($outcome->value);
    }

    private function refreshOwnerBell(EmailAccessRequest $request): void
    {
        $owner = $request->owner;

        if ($owner !== null) {
            $this->refreshBell->execute($owner);
        }
    }
}
