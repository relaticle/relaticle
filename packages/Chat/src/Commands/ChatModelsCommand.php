<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Relaticle\Chat\Services\ModelProbe;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\ModelDescriptor;

#[Description('List the chat model registry and optionally verify a model against its provider')]
#[Signature('chat:models {--probe= : Verify this model id against its provider}')]
final class ChatModelsCommand extends Command
{
    public function handle(ModelRegistry $registry): int
    {
        $rows = array_map(static fn (ModelDescriptor $m): array => [
            $m->id,
            $m->provider ?? '-',
            $m->model ?? '-',
            $m->isAvailable() ? 'yes' : 'no',
            $m->minPlan->value,
            $m->supportsTools ? 'yes' : 'no',
            $m->writeGuard->value,
        ], $registry->all());

        $this->table(['id', 'provider', 'model', 'available', 'min_plan', 'tools', 'write_guard'], $rows);

        $probe = $this->option('probe');

        if (! is_string($probe) || $probe === '') {
            return self::SUCCESS;
        }

        return $this->probe($registry, $probe);
    }

    private function probe(ModelRegistry $registry, string $id): int
    {
        $descriptor = $registry->find($id);

        if (! $descriptor instanceof ModelDescriptor || $descriptor->model === null || $descriptor->provider === null) {
            $this->error("Unknown or unconfigured model: {$id}");

            return self::FAILURE;
        }

        $report = resolve(ModelProbe::class)($descriptor->provider, $descriptor->model);

        if ($report['ok'] === false) {
            $this->error("{$descriptor->model}: {$report['error']}");

            return self::FAILURE;
        }

        $this->info("{$descriptor->model}: accepted by {$descriptor->provider}");
        $this->table(['capability', 'value'], [
            ['supports_tools', $report['supports_tools'] ? 'yes' : 'no'],
            ['write_guard', $report['write_guard']],
        ]);

        return self::SUCCESS;
    }
}
