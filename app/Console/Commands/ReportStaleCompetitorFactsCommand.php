<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CompetitorFacts;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

#[Description('Report competitor facts whose verified date is older than 90 days')]
#[Signature('gtm:stale-facts')]
final class ReportStaleCompetitorFactsCommand extends Command
{
    private const int STALE_AFTER_DAYS = 90;

    public function handle(): int
    {
        $this->info('Checking competitor facts for claims older than '.self::STALE_AFTER_DAYS.' days...');

        $cutoff = Date::now()->subDays(self::STALE_AFTER_DAYS);

        $stale = [];

        foreach (CompetitorFacts::all() as $slug => $fact) {
            $verifiedAt = Date::parse($fact['verified']);

            if ($verifiedAt->greaterThanOrEqualTo($cutoff)) {
                continue;
            }

            $stale[] = [$slug, $fact['name'], $fact['verified'], (int) $verifiedAt->diffInDays().' days ago'];
        }

        if ($stale === []) {
            $this->comment('All facts fresh.');

            return self::SUCCESS;
        }

        $this->table(['slug', 'name', 'verified', 'age'], $stale);
        $this->comment(count($stale).' stale fact(s) — re-verify and bump `verified` in resources/data/competitor-facts.php.');

        return self::SUCCESS;
    }
}
