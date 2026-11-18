<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Merge\MoveMorphPivot;
use App\Models\Company;
use App\Models\People;
use Generator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\RecordCommunicationMetrics;

final readonly class MoveMergedRecordCommunication
{
    private const int CHUNK = 500;

    public function __construct(
        private RecordCommunicationMetrics $metrics,
        private MoveMorphPivot $movePivot,
    ) {}

    public function execute(Company|People $merged, Company|People $original): void
    {
        $this->metrics->combineMergedRecords(
            $merged,
            $original,
            $this->sharedEmails($merged, $original),
            $this->sharedMeetings($merged, $original),
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
        $this->linkedTo($table, $prefix, $merged)
            ->where('link_source', '!=', 'manual')
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $original)->where('link_source', 'manual')->select($itemColumn))
            ->update(['link_source' => 'manual']);
    }

    /**
     * @return LazyCollection<int, Email>
     */
    private function sharedEmails(Company|People $merged, Company|People $original): LazyCollection
    {
        return $this->inChunks(
            $this->sharedIds('emailables', 'email_id', 'emailable', $merged, $original)->pluck('email_id'),
            fn (array $ids): EloquentCollection => Email::query()->withoutGlobalScopes()->with('participants')->whereKey($ids)->get(),
        );
    }

    /**
     * @return LazyCollection<int, Meeting>
     */
    private function sharedMeetings(Company|People $merged, Company|People $original): LazyCollection
    {
        return $this->inChunks(
            $this->sharedIds('meetingables', 'meeting_id', 'meetingable', $merged, $original)->pluck('meeting_id'),
            fn (array $ids): EloquentCollection => Meeting::query()->withoutGlobalScopes()->with(['attendees.contact', 'connectedAccount'])->whereKey($ids)->get(),
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  Collection<int, mixed>  $ids
     * @param  callable(list<mixed>): EloquentCollection<int, TModel>  $load
     * @return LazyCollection<int, TModel>
     */
    private function inChunks(Collection $ids, callable $load): LazyCollection
    {
        return LazyCollection::make(function () use ($ids, $load): Generator {
            foreach ($ids->chunk(self::CHUNK) as $chunk) {
                yield from $load(array_values($chunk->all()))->all();
            }
        });
    }

    private function sharedIds(string $table, string $itemColumn, string $prefix, Company|People $merged, Company|People $original): Builder
    {
        return $this->linkedTo($table, $prefix, $original)
            ->whereIn($itemColumn, $this->linkedTo($table, $prefix, $merged)->select($itemColumn))
            ->select($itemColumn);
    }

    private function linkedTo(string $table, string $prefix, Company|People $record): Builder
    {
        return DB::table($table)->where($prefix.'_type', $record->getMorphClass())->where($prefix.'_id', $record->getKey());
    }
}
