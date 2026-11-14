<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Queries;

use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Enums\CalendarEventStatus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleMeetingScope;
use Relaticle\EmailIntegration\Services\EmailVariableCatalogue;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

final readonly class ConversationFactsQuery
{
    public function __construct(private ConversationsQuery $conversations) {}

    /** @return array{last_from_them: string|null, last_from_us: string|null, waiting_days: int, count: int, opportunity: array{name: string, stage: string}|null, next_meeting: array{title: string, starts_at: string}|null} */
    public function facts(User $viewer, string $key): array
    {
        $messages = $this->conversations->messages($viewer, $key);
        $members = resolve(EmailVisibilityService::class)->memberEmailsForWorkspace($viewer->currentWorkspace);
        $fromThem = $messages->last(fn (Email $email): bool => $this->externalInbound($email, $members));
        $fromUs = $messages->last(fn (Email $email): bool => $this->fromWorkspace($email, $members));
        $waitingSince = $fromUs?->sent_at;

        return [
            'last_from_them' => $fromThem?->sent_at?->toIso8601String(),
            'last_from_us' => $waitingSince?->toIso8601String(),
            'waiting_days' => $waitingSince === null || ($fromThem?->sent_at !== null && $fromThem->sent_at->gt($waitingSince))
                ? 0 : (int) $waitingSince->diffInWeekdays(now()),
            'count' => $messages->count(),
            ...$this->relatedFacts($viewer, array_values($messages->map(fn (Email $email): string => $email->id)->all())),
        ];
    }

    public function replyDraftBlocker(User $viewer, string $key): ?string
    {
        $messages = $this->conversations->messages($viewer, $key);
        $members = resolve(EmailVisibilityService::class)->memberEmailsForWorkspace($viewer->currentWorkspace);
        $latest = $messages->last(fn (Email $email): bool => $this->externalInbound($email, $members));
        if (! $latest instanceof Email || ! $viewer->can('viewBody', $latest)) {
            return 'no_inbound';
        }

        if ($messages->contains(fn (Email $email): bool => $this->fromWorkspace($email, $members) && $email->sent_at?->gt($latest->sent_at))) {
            return 'teammate_answered';
        }

        $addresses = $this->addresses($viewer);
        $roles = $latest->participants->filter(fn (EmailParticipant $participant): bool => in_array(strtolower($participant->email_address), $addresses, true))->pluck('role');
        if ($roles->contains(EmailParticipantRole::TO)) {
            return null;
        }

        return $roles->contains(EmailParticipantRole::CC) ? 'cc_only' : 'not_addressed';
    }

    /** @return list<string> */
    private function addresses(User $viewer): array
    {
        return array_values(ConnectedAccount::query()->ownedBy($viewer, $viewer->currentWorkspace)->pluck('email_address')
            ->push($viewer->email)->map(fn (string $address): string => strtolower($address))->unique()->all());
    }

    /** @param array<int, string> $members */
    private function externalInbound(Email $email, array $members): bool
    {
        return $email->direction === EmailDirection::INBOUND && ! $email->is_internal && $email->automated_reason === null
            && ! $email->participants->contains(fn (EmailParticipant $participant): bool => $participant->role === EmailParticipantRole::FROM && in_array(strtolower($participant->email_address), $members, true));
    }

    /** @param array<int, string> $members */
    private function fromWorkspace(Email $email, array $members): bool
    {
        return $email->direction === EmailDirection::OUTBOUND || $email->participants->contains(
            fn (EmailParticipant $participant): bool => $participant->role === EmailParticipantRole::FROM && in_array(strtolower($participant->email_address), $members, true)
        );
    }

    /**
     * @param  list<string>  $emailIds
     * @return array{opportunity: array{name: string, stage: string}|null, next_meeting: array{title: string, starts_at: string}|null}
     */
    private function relatedFacts(User $viewer, array $emailIds): array
    {
        $opportunity = Opportunity::query()->with(['contact', 'company'])->where('workspace_id', $viewer->current_workspace_id)
            ->whereHas('emails', fn (Builder $query): Builder => $query->whereKey($emailIds))->latest()->limit(10)->get()
            ->map(function (Opportunity $record) use ($viewer): array {
                $values = resolve(EmailVariableCatalogue::class)->values($record, $viewer);

                return ['name' => $record->name, 'stage' => $values['opportunity.stage'] ?? ''];
            })->first(fn (array $record): bool => ! in_array($record['stage'], ['Closed Won', 'Closed Lost'], true));
        $meeting = Meeting::query()->withGlobalScope('visible', new VisibleMeetingScope($viewer))
            ->where('all_day', false)->where('starts_at', '>', now())->where('status', '!=', CalendarEventStatus::CANCELLED)
            ->where(fn (Builder $query): Builder => $query
                ->whereHas('people.emails', fn (Builder $emails): Builder => $emails->whereKey($emailIds))
                ->orWhereHas('companies.emails', fn (Builder $emails): Builder => $emails->whereKey($emailIds)))
            ->oldest('starts_at')->first();

        return ['opportunity' => $opportunity, 'next_meeting' => $meeting === null ? null : ['title' => $meeting->title, 'starts_at' => $meeting->starts_at->toIso8601String()]];
    }
}
