<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final readonly class RecipientSuggestionService
{
    /**
     * Addresses the viewer may reuse in compose autocomplete.
     *
     * Restricted to mail VisibleEmailScope would list, excluding drafts and
     * other people's BCC rows. Without those gates, private, protected,
     * internal, and BCC addresses leak into teammates' To/Cc/Bcc suggestions.
     *
     * @return list<string>
     */
    public function addressesFor(User $user, int $limit = 300): array
    {
        /** @var list<string> */
        return EmailParticipant::query()
            ->whereHas('email', function (Builder $emailQuery) use ($user): void {
                $emailQuery
                    ->withGlobalScope('visible', new VisibleEmailScope($user))
                    ->where('status', '!=', EmailStatus::DRAFT);
            })
            ->where(function (Builder $participantQuery) use ($user): void {
                $participantQuery
                    ->where('role', '!=', EmailParticipantRole::BCC)
                    ->orWhereHas(
                        'email',
                        fn (Builder $ownedEmail): Builder => $ownedEmail->where('user_id', $user->getKey()),
                    );
            })
            ->whereNotNull('email_address')
            ->select('email_address')
            ->distinct()
            ->orderBy('email_address')
            ->limit($limit)
            ->pluck('email_address')
            ->all();
    }
}
