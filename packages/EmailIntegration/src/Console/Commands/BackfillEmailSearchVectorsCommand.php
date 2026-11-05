<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use stdClass;

#[Description('Fill the subject and body search vectors of emails stored before the search triggers')]
#[Signature('email:backfill-search-vectors
    {--force : Write vectors instead of reporting them}
    {--after= : Only emails with an id above this one, the last a queued run read}
    {--workspace= : Only this workspace}
    {--chunk=20000 : Emails to read in one run before queueing the rest}')]
final class BackfillEmailSearchVectorsCommand extends Command
{
    private const int PAGE = 1000;

    public function handle(): int
    {
        if (! $this->option('force')) {
            return $this->report();
        }

        $budget = max(1, (int) $this->option('chunk'));
        [$filled, $read, $lastId] = [0, 0, null];

        $this->pending()->select('id')->chunkById(min(self::PAGE, $budget), function (Collection $rows) use (&$filled, &$read, &$lastId, $budget): bool {
            /** @var list<string> $ids */
            $ids = $rows->pluck('id')->all();
            $filled += $this->fill($ids);
            $read += count($ids);
            $lastId = Arr::last($ids);

            return $read < $budget;
        });

        if ($lastId !== null && $this->pending()->where('id', '>', $lastId)->exists()) {
            return $this->queueRest($lastId, "{$filled} email(s) filled.");
        }

        $this->comment("{$filled} email(s) filled.");

        return self::SUCCESS;
    }

    private function report(): int
    {
        /** @var Collection<int, stdClass> $counts */
        $counts = $this->pending()
            ->select('workspace_id', DB::raw('count(*) as missing'))
            ->groupBy('workspace_id')
            ->orderByDesc('missing')
            ->get();

        if (blank($this->option('workspace')) && $counts->isNotEmpty()) {
            $this->table(['Workspace', 'Emails'], $counts->map(fn (stdClass $row): array => [$row->workspace_id, $row->missing])->all());
        }

        $this->comment("{$counts->sum('missing')} email(s) without search vectors. Re-run with --force to write them.");

        return self::SUCCESS;
    }

    private function pending(): Builder
    {
        return DB::table('emails')
            ->where(fn (Builder $query): Builder => $query->whereNull('subject_search')->orWhereNull('body_search'))
            ->when($this->option('after'), fn (Builder $query, string $id): Builder => $query->where('id', '>', $id))
            ->when($this->option('workspace'), fn (Builder $query, string $id): Builder => $query->where('workspace_id', $id));
    }

    /** @param  list<string>  $ids */
    private function fill(array $ids): int
    {
        // The same expressions as the search triggers. Coalesce keeps a vector a trigger wrote since
        // the read, and the query builder leaves updated_at alone.
        return DB::table('emails')
            ->whereIn('id', $ids)
            ->where(fn (Builder $query): Builder => $query->whereNull('subject_search')->orWhereNull('body_search'))
            ->update([
                'subject_search' => DB::raw("coalesce(subject_search, to_tsvector('simple', coalesce(subject, '')))"),
                'body_search' => DB::raw("coalesce(body_search, to_tsvector('simple', coalesce(snippet, '') || ' ' || left(coalesce((select email_bodies.body_text from email_bodies where email_bodies.email_id = emails.id), ''), 65536)))"),
            ]);
    }

    private function queueRest(string $after, string $progress): int
    {
        Artisan::queue('email:backfill-search-vectors', array_filter([
            '--force' => true,
            '--after' => $after,
            '--workspace' => $this->option('workspace'),
            '--chunk' => $this->option('chunk'),
        ]))->onQueue('imports')->delay(now()->addMinute());

        $this->comment("{$progress} The rest is queued.");

        return self::SUCCESS;
    }
}
