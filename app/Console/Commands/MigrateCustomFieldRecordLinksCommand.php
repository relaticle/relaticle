<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\CustomFields\Console\Commands\UpgradeCommand;

#[Description('Move record field values onto relationship definitions and links')]
#[Signature('custom-fields:migrate-record-links {--force : Write changes instead of reporting them}')]
final class MigrateCustomFieldRecordLinksCommand extends Command
{
    public function handle(): int
    {
        return $this->call(UpgradeCommand::class, $this->option('force')
            ? ['--force' => true]
            : ['--dry-run' => true]);
    }
}
