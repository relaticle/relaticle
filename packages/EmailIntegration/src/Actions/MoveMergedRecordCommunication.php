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
use Relaticle\EmailIntegration\Services\EmailVisibilityService;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;

final readonly class MoveMergedRecordCommunication
{
    public function __construct(
        private EmailVisibilityService $visibility,
        private RecordCommunicationMetrics $metrics,
        private MoveMorphPivot $movePivot,
    ) {}

    public function execute(Company|People $kept, Company|People $duplicate): void
    {
        $morph = $kept->getMorphClass();

        $this->metrics->combineMergedRecords(
            $kept,
            $duplicate,
            $this->sharedEmails($morph, $kept, $duplicate),
            $this->sharedMeetings($morph, $kept, $duplicate)->count(),
        );

        $this->movePivot->execute('emailables', 'email_id', 'emailable', $kept, $duplicate);
        $this->movePivot->execute('meetingables', 'meeting_id', 'meetingable', $kept, $duplicate);

        $column = $kept instanceof Company ? 'company_id' : 'contact_id';

        foreach (['email_participants', 'meeting_attendees'] as $table) {
            DB::table($table)->where($column, $duplicate->getKey())->update([$column => $kept->getKey()]);
        }
    }

    /**
     * @return Collection<int, Email>
     */
    private function sharedEmails(string $morph, Company|People $kept, Company|People $duplicate): Collection
    {
        return Email::query()
            ->withoutGlobalScopes()
            ->with('participants')
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
            ->with(['attendees.contact', 'connectedAccount'])
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

    private function linkedTo(string $table, string $prefix, string $morph, Company|People $record): Builder
    {
        return DB::table($table)->where($prefix.'_type', $morph)->where($prefix.'_id', $record->getKey());
    }
}
