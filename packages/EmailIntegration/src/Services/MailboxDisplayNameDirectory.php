<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

final class MailboxDisplayNameDirectory
{
    /** @var array<string, array<string, string|null>> */
    private array $namesByViewer = [];

    /**
     * Resolve display names from mail the viewer is allowed to see.
     *
     * Restricted to mail VisibleEmailScope would list. Without that gate,
     * names from private, mailbox-blocked, protected, and internal messages
     * leak onto another user's meeting.
     *
     * @param  list<string>  $emails
     */
    public function prime(User $viewer, array $emails): void
    {
        $cacheKey = $this->cacheKey($viewer);
        $missing = [];

        $this->namesByViewer[$cacheKey] ??= [];

        foreach ($emails as $email) {
            $normalized = Str::lower(trim($email));

            if ($normalized === '' || array_key_exists($normalized, $this->namesByViewer[$cacheKey])) {
                continue;
            }

            $this->namesByViewer[$cacheKey][$normalized] = null;
            $missing[] = $normalized;
        }

        if ($missing === []) {
            return;
        }

        $counts = EmailParticipant::query()
            ->select('email_participants.email_address', 'email_participants.name')
            ->selectRaw('count(*) as mentions')
            ->whereIn('email_participants.email_address', $missing)
            ->whereNotNull('email_participants.name')
            ->whereRaw("btrim(email_participants.name) <> ''")
            ->whereRaw('lower(btrim(email_participants.name)) <> email_participants.email_address')
            ->whereHas(
                'email',
                fn (Builder $query): Builder => $query
                    ->withGlobalScope('visible', new VisibleEmailScope($viewer)),
            )
            ->groupBy('email_participants.email_address', 'email_participants.name')
            ->orderByDesc('mentions')
            ->get();

        foreach ($counts as $row) {
            $email = Str::lower(trim((string) $row->email_address));
            $name = trim((string) $row->name);

            if ($email === '' || $name === '' || $this->namesByViewer[$cacheKey][$email] !== null) {
                continue;
            }

            $this->namesByViewer[$cacheKey][$email] = $name;
        }
    }

    /**
     * @param  iterable<int, Meeting>  $meetings
     */
    public function primeFromMeetings(User $viewer, iterable $meetings): void
    {
        $emails = [];

        foreach ($meetings as $meeting) {
            foreach ($meeting->attendees as $attendee) {
                $emails[] = (string) $attendee->email_address;
            }
        }

        $this->prime($viewer, $emails);
    }

    public function find(User $viewer, string $email): ?string
    {
        $normalized = Str::lower(trim($email));

        if ($normalized === '') {
            return null;
        }

        $cacheKey = $this->cacheKey($viewer);

        if (! isset($this->namesByViewer[$cacheKey]) || ! array_key_exists($normalized, $this->namesByViewer[$cacheKey])) {
            $this->prime($viewer, [$normalized]);
        }

        return $this->namesByViewer[$cacheKey][$normalized] ?? null;
    }

    private function cacheKey(User $viewer): string
    {
        return $viewer->getKey().':'.$viewer->current_team_id;
    }
}
