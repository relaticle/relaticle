<?php

declare(strict_types=1);

namespace Relaticle\Chat\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\ModelDescriptor;

#[Description('List the chat model registry and optionally probe a model for tool-calling')]
#[Signature('chat:models {--probe= : Send a tool-calling smoke test to this model id}')]
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

        if (! $descriptor instanceof ModelDescriptor || $descriptor->model === null) {
            $this->error("Unknown or unconfigured model: {$id}");

            return self::FAILURE;
        }

        /** @var array<string, mixed> $connection */
        $connection = config("ai.providers.{$descriptor->provider}", []);
        $base = rtrim((string) ($connection['url'] ?? ''), '/');
        $endpoint = str_ends_with($base, '/v1') ? "{$base}/chat/completions" : "{$base}/api/chat";

        $this->line("Probing {$descriptor->model} at {$endpoint} ...");

        $response = Http::timeout(120)->post($endpoint, [
            'model' => $descriptor->model,
            'stream' => false,
            'messages' => [['role' => 'user', 'content' => "Call create_task with title 'probe'."]],
            'tools' => [[
                'type' => 'function',
                'function' => [
                    'name' => 'create_task',
                    'description' => 'Create a task',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => ['title' => ['type' => 'string']],
                        'required' => ['title'],
                    ],
                ],
            ]],
        ]);

        $body = json_encode($response->json()) ?: '';
        $calledTool = str_contains($body, 'tool_calls') && str_contains($body, 'create_task');

        if ($calledTool) {
            $this->info("OK - {$descriptor->model} returned a tool call.");

            return self::SUCCESS;
        }

        $this->error("{$descriptor->model} did NOT return a tool call - unsafe for CRM write tools.");

        return self::FAILURE;
    }
}
