<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Meeting;

final class MailboxDisplayNameDirectory
{
    /** @var array<string, array<string, string|null>> */
    private array $namesByTeam = [];

    /**
     * @param  list<string>  $emails
     */
    public function prime(string $teamId, array $emails): void
    {
        $missing = [];

        $this->namesByTeam[$teamId] ??= [];

        foreach ($emails as $email) {
            $normalized = Str::lower(trim($email));

            if ($normalized === '' || array_key_exists($normalized, $this->namesByTeam[$teamId])) {
                continue;
            }

            $this->namesByTeam[$teamId][$normalized] = null;
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
                fn (Builder $query): Builder => $query->where('team_id', $teamId),
            )
            ->groupBy('email_participants.email_address', 'email_participants.name')
            ->orderByDesc('mentions')
            ->get();

        foreach ($counts as $row) {
            $email = Str::lower(trim((string) $row->email_address));
            $name = trim((string) $row->name);

            if ($email === '' || $name === '' || $this->namesByTeam[$teamId][$email] !== null) {
                continue;
            }

            $this->namesByTeam[$teamId][$email] = $name;
        }
    }

    /**
     * @param  iterable<int, Meeting>  $meetings
     */
    public function primeFromMeetings(iterable $meetings): void
    {
        $emails = [];
        $teamId = null;

        foreach ($meetings as $meeting) {
            $teamId ??= (string) $meeting->team_id;

            foreach ($meeting->attendees as $attendee) {
                $emails[] = (string) $attendee->email_address;
            }
        }

        if ($teamId === null) {
            return;
        }

        $this->prime($teamId, $emails);
    }

    public function find(string $teamId, string $email): ?string
    {
        $normalized = Str::lower(trim($email));

        if ($normalized === '') {
            return null;
        }

        if (! isset($this->namesByTeam[$teamId]) || ! array_key_exists($normalized, $this->namesByTeam[$teamId])) {
            $this->prime($teamId, [$normalized]);
        }

        return $this->namesByTeam[$teamId][$normalized] ?? null;
    }
}
