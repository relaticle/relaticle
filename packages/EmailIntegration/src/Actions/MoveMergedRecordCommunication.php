<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Merge\MoveMorphPivot;
use App\Models\Company;
use App\Models\People;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;

final readonly class MoveMergedRecordCommunication
{
    public function __construct(
        private RecordCommunicationMetrics $metrics,
        private MoveMorphPivot $movePivot,
    ) {}

    public function execute(Company|People $merged, Company|People $original): void
    {
        $morph = $merged->getMorphClass();

        $this->metrics->combineMergedRecords(
            $merged,
            $original,
            $this->sharedEmails($morph, $merged, $original),
            $this->sharedMeetings($morph, $merged, $original),
        );

        $this->keepManualLinks('emailables', 'email_id', 'emailable', $merged, $original);
        $this->keepManualLinks('meetingables', 'meeting_id', 'meetingable', $merged, $original);

        $this->movePivot->execute('emailables', 'email_id', 'emailable', $merged, $original);
        $this->movePivot->execute('meetingables', 'meeting_id', 'meetingable', $merged, $original);

        $column = $merged instanceof Company ? 'company_id' : 'contact_id';

        foreach (['email_participants', 'meeting_attendees'] as $table) {
            DB::table($table)->where($column, $original->getKey())->update([$column => $merged->getKey()]);
        }
    }

    private function keepManualLinks(string $table, string $itemColumn, string $prefix, Company|People $merged, Company|People $original): void
    {
        $morph = $merged->getMorphClass();

        $this->linkedTo($table, $prefix, $morph, $merged)
            ->where('link_source', '!=', 'manual')
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $morph, $original)->where('link_source', 'manual')->select($itemColumn))
            ->update(['link_source' => 'manual']);
    }

    /**
     * @return Collection<int, Email>
     */
    private function sharedEmails(string $morph, Company|People $merged, Company|People $original): Collection
    {
        return Email::query()
            ->withoutGlobalScopes()
            ->with('participants')
            ->whereIn('id', $this->sharedIds('emailables', 'email_id', 'emailable', $morph, $merged, $original))
            ->get();
    }

    /**
     * @return Collection<int, Meeting>
     */
    private function sharedMeetings(string $morph, Company|People $merged, Company|People $original): Collection
    {
        return Meeting::query()
            ->withoutGlobalScopes()
            ->with(['attendees.contact', 'connectedAccount'])
            ->whereIn('id', $this->sharedIds('meetingables', 'meeting_id', 'meetingable', $morph, $merged, $original))
            ->get();
    }

    private function sharedIds(string $table, string $itemColumn, string $prefix, string $morph, Company|People $merged, Company|People $original): Builder
    {
        return $this->linkedTo($table, $prefix, $morph, $original)
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $morph, $merged)->select($itemColumn))
            ->select($itemColumn);
    }

    private function linkedTo(string $table, string $prefix, string $morph, Company|People $record): Builder
    {
        return DB::table($table)->where($prefix.'_type', $morph)->where($prefix.'_id', $record->getKey());
    }
}
