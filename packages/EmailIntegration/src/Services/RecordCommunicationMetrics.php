<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;

final readonly class RecordCommunicationMetrics
{
    public function __construct(private EmailVisibilityService $visibility) {}

    public function incrementEmailMetrics(Model $record, Email $email): void
    {
        if (! $this->visibility->countsTowardCommunicationIntelligence($email)) {
            return;
        }

        $isInbound = $email->direction === EmailDirection::INBOUND;

        $sets = [
            'email_count = email_count + 1',
            'inbound_email_count = inbound_email_count + ?',
            'outbound_email_count = outbound_email_count + ?',
            'updated_at = ?',
        ];

        /** @var list<mixed> $bindings */
        $bindings = [$isInbound ? 1 : 0, $isInbound ? 0 : 1, now()];

        if ($email->sent_at !== null) {
            $sets[] = 'last_email_at = GREATEST(last_email_at, ?)';
            $sets[] = 'last_interaction_at = GREATEST(last_interaction_at, ?)';
            $bindings[] = $email->sent_at;
            $bindings[] = $email->sent_at;
        }

        $bindings[] = $record->getKey();

        DB::update(
            'update '.$record->getTable().' set '.implode(', ', $sets).' where '.$record->getKeyName().' = ?',
            $bindings,
        );
    }

    public function incrementMeetingMetrics(People|Company|Opportunity $record, Meeting $meeting): void
    {
        if (! $this->visibility->meetingCountsTowardCommunicationIntelligence($meeting)) {
            return;
        }

        $this->advanceMeetingMetrics($record->getTable(), $record->getKey(), $meeting->starts_at);
    }

    /**
     * @param  LazyCollection<int, Email>  $sharedEmails
     * @param  LazyCollection<int, Meeting>  $sharedMeetings
     */
    public function combineMergedRecords(Company|People $merged, Company|People $original, LazyCollection $sharedEmails, LazyCollection $sharedMeetings): void
    {
        // The record takes one original at a time, so its counters already hold the first one's.
        $merged->refresh();

        [$sharedEmailCount, $sharedInbound] = $sharedEmails
            ->filter($this->visibility->countsTowardCommunicationIntelligence(...))
            ->reduce(fn (array $counts, Email $email): array => [$counts[0] + 1, $counts[1] + (int) ($email->direction === EmailDirection::INBOUND)], [0, 0]);
        $sharedMeetingCount = $sharedMeetings->filter($this->visibility->meetingCountsTowardCommunicationIntelligence(...))->count();

        $summed = fn (string $column, int $shared): int => max(0, (int) $merged->getAttribute($column) + (int) $original->getAttribute($column) - $shared);
        $latest = fn (string $column): ?CarbonInterface => collect([$merged->getAttribute($column), $original->getAttribute($column)])->filter()->max();

        DB::table($merged->getTable())->where('id', $merged->getKey())->update([
            'email_count' => $summed('email_count', $sharedEmailCount),
            'inbound_email_count' => $summed('inbound_email_count', $sharedInbound),
            'outbound_email_count' => $summed('outbound_email_count', $sharedEmailCount - $sharedInbound),
            'meeting_count' => $summed('meeting_count', $sharedMeetingCount),
            'last_email_at' => $latest('last_email_at'),
            'last_interaction_at' => $latest('last_interaction_at'),
            'last_meeting_at' => $latest('last_meeting_at'),
        ]);

        DB::table($original->getTable())->where('id', $original->getKey())->update([
            'email_count' => 0,
            'inbound_email_count' => 0,
            'outbound_email_count' => 0,
            'meeting_count' => 0,
            'last_email_at' => null,
            'last_interaction_at' => null,
            'last_meeting_at' => null,
        ]);
    }

    /**
     * @param  list<People|Company|Opportunity>  $records
     */
    public function incrementEmailMetricsInLockOrder(array $records, Email $email): void
    {
        foreach ($this->inLockOrder($records) as $record) {
            $this->incrementEmailMetrics($record, $email);
        }
    }

    /**
     * @param  list<People|Company|Opportunity>  $records
     */
    public function incrementMeetingMetricsInLockOrder(array $records, Meeting $meeting): void
    {
        foreach ($this->inLockOrder($records) as $record) {
            $this->incrementMeetingMetrics($record, $meeting);
        }
    }

    /**
     * @param  array<string, true>  $countedCompanies
     * @param  array<string, true>  $countedPeople
     * @param  array<string, true>  $countedOpportunities
     * @return list<People|Company|Opportunity>
     */
    public function prelinkedRecords(
        Email $email,
        array $countedCompanies,
        array $countedPeople,
        array $countedOpportunities,
    ): array {
        $email->loadMissing(['people', 'companies', 'opportunities']);

        return [
            ...$email->people->except(array_keys($countedPeople)),
            ...$email->companies->except(array_keys($countedCompanies)),
            ...$email->opportunities->except(array_keys($countedOpportunities)),
        ];
    }

    /**
     * @param  list<People|Company|Opportunity>  $records
     * @return list<People|Company|Opportunity>
     */
    private function inLockOrder(array $records): array
    {
        // Two transactions that name the same records in a different order deadlock on the row locks.
        usort($records, fn (Model $a, Model $b): int => [$a->getTable(), $a->getKey()] <=> [$b->getTable(), $b->getKey()]);

        return $records;
    }

    private function advanceMeetingMetrics(string $table, string $id, CarbonInterface $startsAt): void
    {
        DB::table($table)
            ->where('id', $id)
            ->update(['meeting_count' => DB::raw('meeting_count + 1')]);

        $this->advanceTimestamp($table, $id, 'last_meeting_at', $startsAt);
        $this->advanceTimestamp($table, $id, 'last_interaction_at', $startsAt);
    }

    private function advanceTimestamp(string $table, string $id, string $column, CarbonInterface $startsAt): void
    {
        DB::table($table)
            ->where('id', $id)
            ->where(fn (QueryBuilder $query) => $query
                ->whereNull($column)
                ->orWhere($column, '<', $startsAt)
            )
            ->update([$column => $startsAt]);
    }
}
