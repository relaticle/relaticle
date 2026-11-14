<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\LazyCollection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Services\EmailSearchService;

final readonly class RecordEmailAccessQuery
{
    public const int MAX_RECORD_ANSWERS = 3;

    public function __construct(private EmailSearchService $search) {}

    public function shareableCount(User $owner, User $requester, Company|People|Opportunity $record, EmailPrivacyTier $tier): int
    {
        return $this->shareable($owner, $requester, $record, $tier)->count();
    }

    /** @return list<string> */
    public function shareableConversationKeys(User $owner, User $requester, Company|People|Opportunity $record, EmailPrivacyTier $tier): array
    {
        return array_values($this->shareable($owner, $requester, $record, $tier)
            ->whereNotNull('emails.conversation_key')
            ->toBase()
            ->distinct()
            ->pluck('emails.conversation_key')
            ->map(fn (mixed $key): string => (string) $key)
            ->all());
    }

    /** @return LazyCollection<int, Email> */
    public function shareableCopies(User $owner, User $requester, Company|People|Opportunity $record, EmailPrivacyTier $tier): LazyCollection
    {
        return $this->shareable($owner, $requester, $record, $tier)
            ->select(['emails.id', 'emails.workspace_id', 'emails.user_id', 'emails.conversation_key'])
            ->lazyById(500, 'emails.id', 'id');
    }

    /**
     * Copies on the record the viewer sees below Full, counted per mailbox owner, most first.
     *
     * @return Collection<string, int>
     */
    public function lockedCountsByOwner(User $viewer, Company|People|Opportunity $record): Collection
    {
        if (Gate::forUser($viewer)->denies('viewAny', Email::class) || $record->workspace_id !== $viewer->current_workspace_id) {
            return new Collection;
        }

        return $this->delivered(Email::query()->withGlobalScope('visible', new VisibleEmailScope($viewer))->onRecord($record))
            ->where('emails.user_id', '!=', $viewer->getKey())
            ->whereNot(fn (Builder $readable): Builder => $this->search->whereVisibleAtTier($readable, $viewer, EmailPrivacyTier::FULL))
            ->toBase()
            ->selectRaw('emails.user_id, count(*) as aggregate')
            ->groupBy('emails.user_id')
            ->orderByDesc('aggregate')
            ->pluck('aggregate', 'user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->mapWithKeys(fn (int $count, mixed $ownerId): array => [(string) $ownerId => $count]);
    }

    /**
     * Up to three records the owner's copy is on, for the owner to share all their mail on one.
     *
     * @return Collection<int, Company|People|Opportunity>
     */
    public function recordsOf(Email $email): Collection
    {
        $email->loadMissing([
            'companies' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
            'opportunities' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
            'people' => fn (MorphToMany $relation): Builder => $relation->getQuery()->withoutGlobalScope(WorkspaceScope::class),
        ]);

        /** @var Collection<int, Company|People|Opportunity> $linked */
        $linked = collect([
            ...$email->companies,
            ...$email->opportunities,
            ...$email->people,
        ]);

        return $linked
            ->filter(fn (Company|People|Opportunity $record): bool => $record->workspace_id === $email->workspace_id)
            ->take(self::MAX_RECORD_ANSWERS)
            ->values();
    }

    /** @return Builder<Email> */
    private function shareable(User $owner, User $requester, Company|People|Opportunity $record, EmailPrivacyTier $tier): Builder
    {
        $alreadyShared = $tier === EmailPrivacyTier::FULL
            ? [EmailPrivacyTier::FULL->value]
            : [EmailPrivacyTier::SUBJECT->value, EmailPrivacyTier::FULL->value];

        return $this->delivered(Email::query()->onRecord($record))
            ->where('emails.workspace_id', $record->workspace_id)
            ->where('emails.user_id', $owner->getKey())
            ->whereDoesntHave('shares', fn (Builder $shares): Builder => $shares
                ->where('shared_with', $requester->getKey())
                ->whereIn('tier', $alreadyShared));
    }

    /**
     * @param  Builder<Email>  $query
     * @return Builder<Email>
     */
    private function delivered(Builder $query): Builder
    {
        return $query
            ->whereIn('emails.status', [EmailStatus::SYNCED, EmailStatus::SENT])
            ->whereNotNull('emails.sent_at');
    }
}
