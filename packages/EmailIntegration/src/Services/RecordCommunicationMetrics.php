<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     * @param  Collection<int, Email>  $sharedEmails
     */
    public function combineMergedRecords(Company|People $kept, Company|People $duplicate, Collection $sharedEmails, int $sharedMeetings): void
    {
        $sharedInbound = $sharedEmails->where('direction', EmailDirection::INBOUND)->count();
        $table = $kept->getTable();

        $summed = fn (string $column): string => "GREATEST(0, {$column} + (select {$column} from {$table} where id = ?) - ?)";
        $latest = fn (string $column): string => "GREATEST({$column}, (select {$column} from {$table} where id = ?))";

        DB::update(
            "update {$table} set "
            .'email_count = '.$summed('email_count').', '
            .'inbound_email_count = '.$summed('inbound_email_count').', '
            .'outbound_email_count = '.$summed('outbound_email_count').', '
            .'meeting_count = '.$summed('meeting_count').', '
            .'last_email_at = '.$latest('last_email_at').', '
            .'last_interaction_at = '.$latest('last_interaction_at').', '
            .'last_meeting_at = '.$latest('last_meeting_at').' '
            .'where id = ?',
            [
                $duplicate->getKey(), $sharedEmails->count(),
                $duplicate->getKey(), $sharedInbound,
                $duplicate->getKey(), $sharedEmails->count() - $sharedInbound,
                $duplicate->getKey(), $sharedMeetings,
                $duplicate->getKey(),
                $duplicate->getKey(),
                $duplicate->getKey(),
                $kept->getKey(),
            ],
        );
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
