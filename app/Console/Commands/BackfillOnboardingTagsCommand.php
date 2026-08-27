<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use App\Models\Team;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;

#[Description('Re-dispatch Mailcoach onboarding tags for teams created in a given window')]
#[Signature('subscribers:backfill-onboarding-tags {--since= : Inclusive UTC start, e.g. 2026-08-26T02:00} {--until= : Inclusive UTC end, e.g. 2026-08-26T07:00}')]
final class BackfillOnboardingTagsCommand extends Command
{
    public function handle(): int
    {
        if (! config('mailcoach-sdk.enabled_subscribers_sync', false)) {
            $this->error('Mailcoach subscriber sync is disabled.');

            return self::FAILURE;
        }

        $since = $this->option('since');
        $until = $this->option('until');

        if (! is_string($since) || ! is_string($until)) {
            $this->error('Both --since and --until are required.');

            return self::FAILURE;
        }

        $start = Date::parse($since, 'UTC');
        $end = Date::parse($until, 'UTC');

        if ($start->greaterThan($end)) {
            $this->error('--since must be before --until.');

            return self::FAILURE;
        }

        $this->info("Backfilling onboarding tags for teams created between {$start->toDateTimeString()} and {$end->toDateTimeString()} UTC.");

        $dispatched = 0;
        $skipped = 0;

        Team::query()
            ->whereBetween('created_at', [$start, $end])
            ->select(['id', 'name', 'user_id', 'onboarding_use_case', 'onboarding_referral_source'])
            ->chunkById(200, function (Collection $teams) use (&$dispatched, &$skipped): void {
                /** @var Team $team */
                foreach ($teams as $team) {
                    $tags = $team->onboardingSubscriberTags();

                    if ($tags === []) {
                        $skipped++;

                        continue;
                    }

                    $this->line('Dispatching '.count($tags)." tag(s) for team '{$team->name}' ({$team->id}).");

                    dispatch(new ModifySubscriberTagsJob(
                        (string) $team->user_id,
                        $tags,
                        TagAction::Add,
                    ));

                    $dispatched++;
                }
            });

        $this->comment("Dispatched {$dispatched} tag job(s), skipped {$skipped} team(s) with no onboarding answers.");

        return self::SUCCESS;
    }
}
