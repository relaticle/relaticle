<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Listeners;

use App\Events\RecordMerged;
use App\Models\Company;
use App\Models\People;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use stdClass;

final readonly class MoveMergedRecordCommunicationListener
{
    private const array COUNTERS = ['email_count', 'inbound_email_count', 'outbound_email_count', 'meeting_count'];

    private const array LATEST = ['last_email_at', 'last_interaction_at', 'last_meeting_at'];

    public function __construct(private EmailVisibilityService $visibility) {}

    public function handle(RecordMerged $event): void
    {
        $kept = $event->kept;
        $duplicate = $event->duplicate;
        $morph = $kept->getMorphClass();

        $counters = $this->combinedCounters($morph, $kept, $duplicate);

        $this->movePivot('emailables', 'email_id', 'emailable', $morph, $kept, $duplicate);
        $this->movePivot('meetingables', 'meeting_id', 'meetingable', $morph, $kept, $duplicate);

        $column = $kept instanceof Company ? 'company_id' : 'contact_id';

        foreach (['email_participants', 'meeting_attendees'] as $table) {
            DB::table($table)->where($column, $duplicate->getKey())->update([$column => $kept->getKey()]);
        }

        DB::table($kept->getTable())->where('id', $kept->getKey())->update($counters);
    }

    /**
     * @return array<string, int|string|null>
     */
    private function combinedCounters(string $morph, Company|People $kept, Company|People $duplicate): array
    {
        $table = $kept->getTable();
        $keptRow = DB::table($table)->where('id', $kept->getKey())->first();
        $duplicateRow = DB::table($table)->where('id', $duplicate->getKey())->first();

        $counters = [];

        foreach (self::COUNTERS as $counter) {
            $counters[$counter] = $keptRow->{$counter} + $duplicateRow->{$counter};
        }

        foreach ($this->sharedEmails($morph, $kept, $duplicate) as $email) {
            $counters['email_count']--;
            $counters[$email->direction === EmailDirection::INBOUND ? 'inbound_email_count' : 'outbound_email_count']--;
        }

        $counters['meeting_count'] -= $this->sharedMeetings($morph, $kept, $duplicate)->count();

        foreach (self::COUNTERS as $counter) {
            $counters[$counter] = max(0, (int) $counters[$counter]);
        }

        foreach (self::LATEST as $column) {
            $counters[$column] = $this->later($keptRow, $duplicateRow, $column);
        }

        return $counters;
    }

    private function later(stdClass $kept, stdClass $duplicate, string $column): ?string
    {
        return collect([$kept->{$column}, $duplicate->{$column}])->filter()->max();
    }

    /**
     * @return Collection<int, Email>
     */
    private function sharedEmails(string $morph, Company|People $kept, Company|People $duplicate): Collection
    {
        return Email::query()
            ->withoutGlobalScopes()
            ->whereKey($this->sharedIds('emailables', 'email_id', 'emailable', $morph, $kept, $duplicate))
            ->get()
            ->filter(fn (Email $email): bool => $this->visibility->countsTowardCommunicationIntelligence($email));
    }

    /**
     * @return Collection<int, Meeting>
     */
    private function sharedMeetings(string $morph, Company|People $kept, Company|People $duplicate): Collection
    {
        return Meeting::query()
            ->withoutGlobalScopes()
            ->whereKey($this->sharedIds('meetingables', 'meeting_id', 'meetingable', $morph, $kept, $duplicate))
            ->get()
            ->filter(fn (Meeting $meeting): bool => $this->visibility->meetingCountsTowardCommunicationIntelligence($meeting));
    }

    /**
     * @return array<int, string>
     */
    private function sharedIds(string $table, string $itemColumn, string $prefix, string $morph, Company|People $kept, Company|People $duplicate): array
    {
        return $this->linkedTo($table, $prefix, $morph, $duplicate)
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $morph, $kept)->select($itemColumn))
            ->pluck($itemColumn)
            ->all();
    }

    private function movePivot(string $table, string $itemColumn, string $prefix, string $morph, Company|People $kept, Company|People $duplicate): void
    {
        $this->linkedTo($table, $prefix, $morph, $duplicate)
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $morph, $kept)->select($itemColumn))
            ->delete();

        $this->linkedTo($table, $prefix, $morph, $duplicate)->update([$prefix.'_id' => $kept->getKey()]);
    }

    private function linkedTo(string $table, string $prefix, string $morph, Company|People $record): Builder
    {
        return DB::table($table)->where($prefix.'_type', $morph)->where($prefix.'_id', $record->getKey());
    }
}
