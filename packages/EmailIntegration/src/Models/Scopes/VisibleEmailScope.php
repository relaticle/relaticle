<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Models\Scopes;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;

/**
 * Excludes emails that are entirely private to another user.
 * Fine-grained field masking happens at the view/policy layer.
 */
final readonly class VisibleEmailScope implements Scope
{
    public function __construct(private User $viewer) {}

    public function apply(Builder $builder, Model $model): void
    {
        $viewerId = $this->viewer->getKey();

        $builder->where(function (Builder $q) use ($viewerId): void {
            // Owner always sees their own emails
            $q->where('user_id', $viewerId)
                ->orWhere(function (Builder $q) use ($viewerId): void {
                    $q->where('is_internal', false)
                        ->where('privacy_tier', '!=', EmailPrivacyTier::PRIVATE->value)
                        ->orWhereHas('shares', fn (Builder $shareQuery) => $shareQuery->where('shared_with', $viewerId));
                });
        });
    }
}
