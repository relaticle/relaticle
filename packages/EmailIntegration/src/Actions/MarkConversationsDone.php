<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Relaticle\EmailIntegration\Data\TriageOutcome;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailConversationState;
use Relaticle\EmailIntegration\Queries\ConversationsQuery;

final readonly class MarkConversationsDone
{
    public function __construct(private PrepareConversationTriage $prepare) {}

    /** @param  array<mixed>  $seenThrough  the newest external inbound each row showed, keyed by conversation key, null when it showed none */
    public function execute(User $user, array $seenThrough): TriageOutcome
    {
        $seen = [];

        foreach ($seenThrough as $key => $value) {
            $seen[(string) $key] = $this->seenAt($value);
        }

        $outcome = $this->prepare->execute($user, array_keys($seen));

        if ($outcome->appliedKeys === []) {
            return $outcome;
        }

        $now = now();
        $current = EmailConversation::query()
            ->where('workspace_id', $user->current_workspace_id)
            ->whereIn('conversation_key', $outcome->appliedKeys)
            ->pluck('last_external_inbound_at', 'conversation_key');

        EmailConversationState::query()->upsert(array_map(fn (string $key): array => [
            'id' => (string) Str::ulid(),
            'workspace_id' => $user->current_workspace_id,
            'user_id' => $user->getKey(),
            'conversation_key' => $key,
            'done_at' => $now,
            'done_through' => $this->through($seen[$key], $current->get($key)),
            'created_at' => $now,
            'updated_at' => $now,
        ], $outcome->appliedKeys), ['workspace_id', 'user_id', 'conversation_key'], ['done_at', 'done_through', 'updated_at']);

        Cache::forget(ConversationsQuery::countsCacheKey($user));

        return $outcome;
    }

    private function seenAt(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        try {
            if (is_string($value) && $value !== '') {
                return Date::parse($value)->utc();
            }
        } catch (InvalidFormatException) {
        }

        throw ValidationException::withMessages(['keys' => __('filament/pages/email-inbox.triage.stale')]);
    }

    // What the viewer saw never outranks the summary, so a client cannot bury mail it was not shown.
    private function through(?CarbonImmutable $seen, ?CarbonImmutable $current): ?CarbonImmutable
    {
        return ! $seen instanceof CarbonImmutable || ! $current instanceof CarbonImmutable ? null : $seen->min($current);
    }
}
