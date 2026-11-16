<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\ToolResult;
use RuntimeException;

final class ClaudeCodeProcess
{
    private const float IDLE_LIMIT_SECONDS = 900;

    private const float SIBLING_GRACE_SECONDS = 0.4;

    private const array REMOVED_ENV = [
        'CLAUDECODE',
        'CLAUDE_CODE_ENTRYPOINT',
        'CLAUDE_CODE_SESSION_ID',
        'CLAUDE_CODE_CHILD_SESSION',
        'CLAUDE_CODE_MESSAGING_SOCKET',
        'CLAUDE_CODE_MESSAGING_TOKEN',
        'ANTHROPIC_API_KEY',
        'ANTHROPIC_AUTH_TOKEN',
    ];

    private string $buffer = '';

    /** @var list<string> */
    private array $messageLines = [];

    /** @var list<ToolResult> */
    private array $unanswered = [];

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    private float $lastOutputAt;

    private bool $released = false;

    /**
     * @param  array<string, mixed>  $init
     */
    private function __construct(
        private readonly InvokedProcess $process,
        private readonly ToolRendezvous $rendezvous,
        private readonly string $directory,
        public readonly string $firstInput,
        private array $init = [],
    ) {
        $this->lastOutputAt = microtime(true);
    }

    /**
     * @param  list<array{name: string, description: string, inputSchema: array<string, mixed>}>  $tools
     */
    public static function start(string $model, string $effort, string $instructions, string $firstMessage, array $tools): self
    {
        $directory = sys_get_temp_dir().'/cc'.bin2hex(random_bytes(4));
        mkdir($directory.'/w', 0700, true);

        $rendezvous = ToolRendezvous::listen($directory);
        file_put_contents($directory.'/tools.json', json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/mcp.json', json_encode(['mcpServers' => ['rela' => [
            'command' => PHP_BINARY,
            'args' => [dirname(__DIR__, 2).'/bin/claude-tool-stub.php', $directory.'/tools.json', $rendezvous->path()],
        ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $input = json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $firstMessage]]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $process = Process::path($directory.'/w')
            ->forever()
            ->env([...array_fill_keys(self::REMOVED_ENV, false), 'ENABLE_TOOL_SEARCH' => 'false'])
            ->input($input."\n")
            ->start([
                'claude', '-p',
                '--input-format', 'stream-json',
                '--output-format', 'stream-json',
                '--verbose',
                '--system-prompt', $instructions,
                '--model', $model,
                '--effort', $effort,
                '--tools', '',
                '--mcp-config', $directory.'/mcp.json',
                '--strict-mcp-config',
                '--allowedTools', ...array_map(fn (array $tool): string => StreamJson::TOOL_PREFIX.$tool['name'], $tools),
                '--setting-sources', '',
                '--disable-slash-commands',
                '--no-session-persistence',
            ]);

        return new self($process, $rendezvous, $directory, $input);
    }

    /**
     * @param  list<ToolResult>  $results
     */
    public function deliver(array $results): void
    {
        $this->unanswered = $results;

        while ($this->unanswered !== []) {
            $this->answerWaiting();

            if ($this->unanswered === [] || $this->rendezvous->accept(0.25) !== null) {
                continue;
            }

            $this->pump();
            $this->assertAlive();
        }
    }

    public function nextStep(): StepResponse
    {
        while (true) {
            $line = $this->takeLine();

            if ($line !== null) {
                $step = $this->handle($line);

                if ($step instanceof StepResponse) {
                    return $step;
                }

                continue;
            }

            if ($this->messageLines !== [] && $this->rendezvous->accept(0.05) !== null) {
                return $this->closeMessage();
            }

            $this->assertAlive();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function result(): ?array
    {
        return $this->result;
    }

    /**
     * @return array<string, mixed>
     */
    public function init(): array
    {
        return $this->init;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        if ($this->process->running()) {
            $this->process->signal(SIGTERM);
        }

        $this->rendezvous->close();
        File::deleteDirectory($this->directory);
    }

    private function handle(string $line): ?StepResponse
    {
        $event = StreamJson::decode($line);

        if (($event['type'] ?? '') === 'system' && ($event['subtype'] ?? '') === 'init') {
            $this->init = $event;
            $this->assertSubscription($event);

            return null;
        }

        if (StreamJson::isAssistant($line)) {
            $this->messageLines[] = $line;

            return null;
        }

        if (! StreamJson::isEnd($line)) {
            return null;
        }

        $this->result = $event;

        if (($event['is_error'] ?? false) === true) {
            throw new RuntimeException('Claude Code failed: '.mb_substr((string) ($event['result'] ?? json_encode($event)), 0, 500));
        }

        $step = StreamJson::step(...$this->messageLines);
        $this->messageLines = [];

        return $step->toolCalls === []
            ? $step
            : throw new RuntimeException('Claude Code ended the turn after calling a tool it did not run: '.$step->toolCalls[0]->name);
    }

    private function closeMessage(): StepResponse
    {
        $this->rendezvous->accept(self::SIBLING_GRACE_SECONDS);
        $this->pump();

        while (($line = $this->takeLine()) !== null) {
            if (StreamJson::isAssistant($line)) {
                $this->messageLines[] = $line;
            }
        }

        $step = StreamJson::step(...$this->messageLines);
        $this->messageLines = [];

        return $step;
    }

    private function answerWaiting(): void
    {
        foreach ($this->rendezvous->waiting() as $index => $call) {
            $match = $this->matchResult($call['name'], $call['arguments']);

            if ($match === null) {
                continue;
            }

            $this->rendezvous->reply($match->text(), ! $match->successful(), $index);
            $this->unanswered = array_values(array_filter($this->unanswered, fn (ToolResult $result): bool => $result !== $match));

            $this->answerWaiting();

            return;
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function matchResult(string $name, array $arguments): ?ToolResult
    {
        $named = array_values(array_filter($this->unanswered, fn (ToolResult $result): bool => $result->name === $name));

        foreach ($named as $result) {
            if (self::canonical($result->arguments) === self::canonical($arguments)) {
                return $result;
            }
        }

        return $named[0] ?? null;
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private static function canonical(array $value): string
    {
        $sort = function (array $node) use (&$sort): array {
            ksort($node);

            return array_map(fn (mixed $child): mixed => is_array($child) ? $sort($child) : $child, $node);
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR);
    }

    private function takeLine(): ?string
    {
        $this->pump();

        $newline = strpos($this->buffer, "\n");

        if ($newline === false) {
            return null;
        }

        $line = substr($this->buffer, 0, $newline);
        $this->buffer = substr($this->buffer, $newline + 1);

        return trim($line) === '' ? $this->takeLine() : $line;
    }

    private function pump(): void
    {
        $output = $this->process->latestOutput();

        if ($output !== '') {
            $this->buffer .= $output;
            $this->lastOutputAt = microtime(true);
        }
    }

    private function assertAlive(): void
    {
        if (! $this->process->running() && $this->pumpedNothing()) {
            throw new RuntimeException('Claude Code exited before the turn ended: '.mb_substr(trim($this->process->errorOutput()) ?: 'no error output', 0, 500));
        }

        if (microtime(true) - $this->lastOutputAt > self::IDLE_LIMIT_SECONDS) {
            throw new RuntimeException('Claude Code wrote nothing for '.self::IDLE_LIMIT_SECONDS.' seconds.');
        }
    }

    private function pumpedNothing(): bool
    {
        $this->pump();

        return $this->buffer === '' || ! str_contains($this->buffer, "\n");
    }

    /**
     * @param  array<string, mixed>  $init
     */
    private function assertSubscription(array $init): void
    {
        if (($init['apiKeySource'] ?? 'none') !== 'none') {
            throw new RuntimeException('Claude Code is not signed in with the plan, its key source is '.json_encode($init['apiKeySource']));
        }
    }
}
