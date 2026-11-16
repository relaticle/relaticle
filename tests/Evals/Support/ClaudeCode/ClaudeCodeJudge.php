<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Illuminate\Support\Facades\Process;
use Pest\Evals\Contracts\JudgeDriver;
use Pest\Evals\Eval\Evaluation;
use Pest\Evals\Eval\Verdict;
use RuntimeException;

final readonly class ClaudeCodeJudge implements JudgeDriver
{
    private const array REMOVED_ENV = ['CLAUDECODE', 'CLAUDE_CODE_ENTRYPOINT', 'CLAUDE_CODE_SESSION_ID', 'CLAUDE_CODE_CHILD_SESSION', 'CLAUDE_CODE_MESSAGING_SOCKET', 'CLAUDE_CODE_MESSAGING_TOKEN', 'ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN'];

    public function __construct(private string $model) {}

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
                ->forever()
                ->env(array_fill_keys(self::REMOVED_ENV, false))
                ->input($prompt)
                ->run([
                    'claude', '-p',
                    '--output-format', 'json',
                    '--model', $this->model,
                    '--system-prompt', "You are an expert evaluator of AI agent responses. {$evaluation->question}\n\nChoose the number of the level, ordered from worst to best, that best describes the output:\n{$levels}",
                    '--tools', '',
                    '--setting-sources', '',
                    '--disable-slash-commands',
                    '--no-session-persistence',
                    '--json-schema', $schema,
                ]);
        } finally {
            @rmdir($directory);
        }

        $payload = json_decode($result->output(), true);

        if (! is_array($payload) || ($payload['is_error'] ?? true) === true) {
            throw new RuntimeException('The plan judge failed: '.mb_substr($result->output() ?: $result->errorOutput(), 0, 500));
        }

        $verdict = is_array($payload['structured_output'] ?? null) ? $payload['structured_output'] : [];
        $label = is_numeric($verdict['level'] ?? null) ? ($labels[(int) $verdict['level']] ?? null) : null;

        if ($label === null) {
            throw new RuntimeException('The plan judge returned no valid level: '.json_encode($verdict));
        }

        return new Verdict($evaluation->levels[$label], is_string($verdict['reasoning'] ?? null) ? $verdict['reasoning'] : 'No reasoning provided.', $label);
    }
}
