<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CustomFields\ConvertSeededStatusFields;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Description('Convert the seeded task status and opportunity stage fields to the status type and categorise their options')]
#[Signature('custom-fields:backfill-categories
                            {--team= : Specific team ID to backfill (optional)}
                            {--dry-run : Show what would be updated without making changes}')]
final class BackfillCustomFieldCategoriesCommand extends Command
{
    public function handle(ConvertSeededStatusFields $convert): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $team = $this->option('team');

        if ($dryRun) {
            $this->warn('Dry run: no changes will be written.');
        }

        $summaries = $convert->execute(is_string($team) && $team !== '' ? $team : null, $dryRun);

        $unmatched = [];

        foreach ($summaries as $tenantId => $summary) {
            $this->line("Team {$tenantId}: {$summary['converted']} fields converted, {$summary['categorised']} options categorised, {$summary['skipped']} already categorised.");

            foreach ($summary['unmatched'] as $option) {
                $unmatched[] = "Team {$tenantId}, {$option}";
            }
        }

        $this->info(count($summaries).' workspaces processed.');

        if ($unmatched !== []) {
            $this->warn(count($unmatched).' renamed options stay uncategorised and need a category in the field editor:');

            foreach ($unmatched as $line) {
                $this->line("  {$line}");
            }
        }

        return self::SUCCESS;
    }
}
