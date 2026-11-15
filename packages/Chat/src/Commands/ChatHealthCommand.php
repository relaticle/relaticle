<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Chat\Queries\ChatHealthQuery;

#[Description('Report chat turn failures, tool errors, cache reads and the heaviest workspaces. Read-only.')]
#[Signature('chat:health {--days=7 : Window length in days} {--json : Print the report as JSON}')]
final class ChatHealthCommand extends Command
{
    public function handle(ChatHealthQuery $query): int
    {
        $report = $query->forWindow(now()->subDays(max(1, (int) $this->option('days'))), now());

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->table(['metric', 'value'], collect($report)->map(static fn (mixed $value, string $key): array => [
            $key,
            is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : (string) $value,
        ])->values()->all());

        return self::SUCCESS;
    }
}
