<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

/**
 * Excludes emails that are entirely private to another user.
 * Fine-grained field masking happens at the view/policy layer.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final readonly class VisibleEmailScope implements Scope
{
    public function __construct(private User $viewer) {}

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $viewerId = $this->viewer->getKey();
        $teamId = $this->viewer->current_team_id;

        $builder
            ->where('team_id', $teamId)
            ->where(function (Builder $visibilityQuery) use ($viewerId, $teamId): void {
                // Owner always sees their own emails
                $visibilityQuery->where('user_id', $viewerId)
                    ->orWhere(function (Builder $sharedQuery) use ($viewerId, $teamId): void {
                        $sharedQuery->where(function (Builder $publicGate) use ($teamId): void {
                            $publicGate->where('is_internal', false)
                                ->where('privacy_tier', '!=', EmailPrivacyTier::PRIVATE->value);

                            // Protected-recipient emails are hard-hidden from everyone but the
                            // owner — mirror PrivacyService::effectiveTier() so the list/lookup
                            // SQL gate and the policy agree. Without this, a protected email at
                            // a non-PRIVATE tier (e.g. METADATA_ONLY) would leak into teammates'
                            // inbox lists even though the policy hides it.
                            $this->excludeProtectedRecipients($publicGate, $teamId);
                        })->orWhereHas('shares', fn (Builder $shareQuery) => $shareQuery->where('shared_with', $viewerId));
                    });
            });
    }

    /**
     * @param  Builder<covariant TModel>  $builder
     */
    private function excludeProtectedRecipients(Builder $builder, ?string $teamId): void
    {
        if ($teamId === null) {
            return;
        }

        $builder->whereDoesntHave('participants', function (Builder $participantQuery) use ($teamId): void {
            $participantQuery->whereExists(function (BaseBuilder $protectedQuery) use ($teamId): void {
                $protectedQuery->from('protected_recipients')
                    ->where('protected_recipients.team_id', $teamId)
                    ->where(function (BaseBuilder $match): void {
                        // Exact address match.
                        $match->where(function (BaseBuilder $emailMatch): void {
                            $emailMatch->where('protected_recipients.type', 'email')
                                ->whereRaw('lower(protected_recipients.value) = lower(email_participants.email_address)');
                            // Domain match against the host after the last '@'.
                        })->orWhere(function (BaseBuilder $domainMatch): void {
                            $domainMatch->where('protected_recipients.type', 'domain')
                                ->whereRaw("lower(email_participants.email_address) like '%@' || lower(protected_recipients.value)");
                        });
                    });
            });
        });
    }
}
