<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Pest\Evals\Contracts\JudgeDriver;
use Pest\Evals\Eval\Evaluation;
use Pest\Evals\Eval\Verdict;
use RuntimeException;

final readonly class ClaudeCodeJudge implements JudgeDriver
{
    private const int TIMEOUT_SECONDS = 300;

    public function __construct(private string $model) {}

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function readStream(string $output): array
    {
        $init = [];
        $result = [];

        foreach (array_filter(explode("\n", $output)) as $line) {
            $event = json_decode($line, true);

            if (is_array($event) && ($event['type'] ?? '') === 'system' && ($event['subtype'] ?? '') === 'init') {
                $init = $event;
            } elseif (is_array($event) && ($event['type'] ?? '') === 'result') {
                $result = $event;
            }
        }

        return [$init, $result];
    }

    public function judge(Evaluation $evaluation): Verdict
    {
        $labels = $evaluation->labels();
        $levels = implode("\n", array_map(fn (int $index, string $label): string => "{$index}: {$label}", array_keys($labels), $labels));
        $schema = json_encode([
            'type' => 'object',
            'properties' => [
                'reasoning' => ['type' => 'string'],
                'level' => ['type' => 'integer', 'minimum' => 0, 'maximum' => count($labels) - 1],
            ],
            'required' => ['reasoning', 'level'],
        ], JSON_THROW_ON_ERROR);

        $prompt = implode("\n\n", array_map(fn (string $key, string $value): string => '## '.ucfirst($key)."\n{$value}", array_keys($evaluation->state), $evaluation->state));
        $directory = sys_get_temp_dir().'/ccj'.bin2hex(random_bytes(4));
        mkdir($directory, 0700);

        try {
            $result = Process::path($directory)
                ->timeout(self::TIMEOUT_SECONDS)
                ->env(array_fill_keys(ClaudeCodeProcess::REMOVED_ENV, false))
                ->input($prompt)
                ->run([
                    ...ClaudeCodeProcess::$command, '-p',
                    '--output-format', 'stream-json',
                    '--verbose',
                    '--model', $this->model,
                    '--system-prompt', "You are an expert evaluator of AI agent responses. {$evaluation->question}\n\nChoose the number of the level, ordered from worst to best, that best describes the output:\n{$levels}",
                    '--tools', '',
                    '--strict-mcp-config',
                    '--mcp-config', '{"mcpServers":{}}',
                    '--setting-sources', '',
                    '--disable-slash-commands',
                    '--no-session-persistence',
                    '--json-schema', $schema,
                ]);
        } catch (ProcessTimedOutException $e) {
            throw new RuntimeException('The plan judge timed out: '.$e->getMessage(), previous: $e);
        } finally {
            @rmdir($directory);
        }

        [$init, $payload] = $this->readStream($result->output());

        if (($init['apiKeySource'] ?? null) !== 'none' || ($init['mcp_servers'] ?? []) !== [] || ($init['model'] ?? null) !== $this->model) {
            throw new RuntimeException('The plan judge did not run as asked: '.json_encode(array_intersect_key($init, array_flip(['apiKeySource', 'mcp_servers', 'model']))));
        }

        if (($payload['is_error'] ?? true) === true || ! array_key_exists($this->model, (array) ($payload['modelUsage'] ?? []))) {
            throw new RuntimeException('The plan judge failed: '.mb_substr((string) ($payload['result'] ?? $result->errorOutput() ?: $result->output()), 0, 500));
        }

        $verdict = is_array($payload['structured_output'] ?? null) ? $payload['structured_output'] : [];
        $label = is_numeric($verdict['level'] ?? null) ? ($labels[(int) $verdict['level']] ?? null) : null;

        if ($label === null) {
            throw new RuntimeException('The plan judge returned no valid level: '.json_encode($verdict));
        }

        return new Verdict($evaluation->levels[$label], is_string($verdict['reasoning'] ?? null) ? $verdict['reasoning'] : 'No reasoning provided.', $label);
    }
}
