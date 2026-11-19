<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use RuntimeException;
use Throwable;

final class ClaudeCodeProcess
{
    public const array DEFAULT_COMMAND = ['claude'];

    /** @var list<string> */
    public static array $command = self::DEFAULT_COMMAND;

    public static ?string $tempRoot = null;

    private const float IDLE_LIMIT_SECONDS = 900;

    private const float POLL_SECONDS = 0.05;

    public const array REMOVED_ENV = [
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

    private ?string $messageId = null;

    /** @var list<string> */
    private array $held = [];

    /** @var array<string, true> */
    private array $answeredByClaude = [];

    /** @var list<array{id: string, name: string}> */
    private array $rejected = [];

    /** @var list<array{id: string|null, tool_use_ids: list<string>}> */
    private array $messages = [];

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    private float $lastOutputAt;

    private bool $released = false;

    /**
     * @param  array<string, mixed>  $init
     * @param  list<string>  $toolNames
     */
    private function __construct(
        private readonly InvokedProcess $process,
        private readonly ToolRendezvous $rendezvous,
        private readonly string $directory,
        public readonly string $firstInput,
        private readonly array $toolNames,
        private array $init = [],
    ) {
        $this->lastOutputAt = microtime(true);
    }

    /**
     * @param  list<array{name: string, description: string, inputSchema: array<string, mixed>}>  $tools
     */
    public static function start(string $model, string $effort, string $instructions, string $firstMessage, array $tools): self
    {
        $directory = (self::$tempRoot ?? sys_get_temp_dir()).'/cc'.bin2hex(random_bytes(4));
        $rendezvous = null;

        try {
            mkdir($directory.'/w', 0700, true);
            $rendezvous = ToolRendezvous::listen($directory);

            file_put_contents($directory.'/tools.json', json_encode($tools, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            file_put_contents($directory.'/mcp.json', json_encode(['mcpServers' => ['rela' => [
                'command' => PHP_BINARY,
                'args' => [dirname(__DIR__, 2).'/bin/claude-tool-stub.php', $directory.'/tools.json', $rendezvous->path()],
            ]]], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            $names = array_map(fn (array $tool): string => StreamJson::TOOL_PREFIX.$tool['name'], $tools);
            $input = json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => $firstMessage]]]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            $process = Process::path($directory.'/w')
                ->forever()
                ->env([...array_fill_keys(self::REMOVED_ENV, false), 'ENABLE_TOOL_SEARCH' => 'false'])
                ->input($input."\n")
                ->start([
                    ...self::$command, '-p',
                    '--input-format', 'stream-json',
                    '--output-format', 'stream-json',
                    '--verbose',
                    '--include-partial-messages',
                    '--system-prompt', $instructions,
                    '--model', $model,
                    '--effort', $effort,
                    '--tools', '',
                    '--mcp-config', $directory.'/mcp.json',
                    '--strict-mcp-config',
                    '--allowedTools', ...$names,
                    '--setting-sources', '',
                    '--disable-slash-commands',
                    '--no-session-persistence',
                ]);
        } catch (Throwable $e) {
            $rendezvous?->close();
            File::deleteDirectory($directory);

            throw $e;
        }

        return new self($process, $rendezvous, $directory, $input, $names);
    }

    /**
     * @param  list<ToolResult>  $results
     */
    public function deliver(array $results): void
    {
        $pending = $results;

        while ($pending !== []) {
            $this->consumeLines();
            $progressed = false;

            foreach ($pending as $key => $result) {
                $index = $this->rendezvous->indexOf($result->id, $result->name, $result->arguments);

                if ($index !== null) {
                    $this->rendezvous->reply($result->text(), ! $result->successful(), $index);
                } elseif (isset($this->answeredByClaude[$result->id])) {
                    $this->rejected[] = ['id' => $result->id, 'name' => $result->name];
                } else {
                    continue;
                }

                unset($pending[$key]);
                $progressed = true;
            }

            if (! $progressed) {
                $this->idle();
            }
        }
    }

    public function nextStep(): StepResponse
    {
        $carried = null;

        while (true) {
            $step = $this->readMessage();

            if (! $step instanceof StepResponse) {
                return $carried ?? throw new RuntimeException('Claude Code ended the turn without a message.');
            }

            $step = $carried instanceof StepResponse ? $this->merge($carried, $step) : $step;
            $executed = $this->executed($step->toolCalls);

            if ($step->toolCalls === []) {
                $this->readToResult();

                return $step;
            }

            if ($executed === []) {
                $carried = $this->withoutCalls($step);

                continue;
            }

            $step->toolCalls = $executed;

            return $step;
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

    /**
     * @return list<array{id: string, name: string}>
     */
    public function rejected(): array
    {
        return $this->rejected;
    }

    /**
     * @return list<array{id: string|null, tool_use_ids: list<string>}>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;

        foreach ([
            fn () => $this->process->stop(3),
            fn () => $this->rendezvous->close(),
            fn () => File::deleteDirectory($this->directory),
        ] as $cleanup) {
            try {
                $cleanup();
            } catch (Throwable) {
                continue;
            }
        }
    }

    /**
     * Claude Code runs tools that write one at a time, so a later call of the message
     * connects only after the earlier one is answered. A call it refused answers itself.
     *
     * @param  list<ToolCall>  $calls
     * @return list<ToolCall>
     */
    private function executed(array $calls): array
    {
        $this->waitFor(fn (): ?bool => array_filter($calls, fn (ToolCall $call): bool => $this->rendezvous->indexOf($call->id, $call->name, $call->arguments) !== null) !== [] || $this->unanswered($calls) === [] ? true : null);

        foreach (array_filter($calls, fn (ToolCall $call): bool => isset($this->answeredByClaude[$call->id])) as $call) {
            $this->rejected[] = ['id' => $call->id, 'name' => $call->name];
        }

        return $this->unanswered($calls);
    }

    /**
     * @param  list<ToolCall>  $calls
     * @return list<ToolCall>
     */
    private function unanswered(array $calls): array
    {
        return array_values(array_filter($calls, fn (ToolCall $call): bool => ! isset($this->answeredByClaude[$call->id])));
    }

    private function readMessage(): ?StepResponse
    {
        while (true) {
            $line = $this->takeLine();

            if ($line === null) {
                $this->idle();

                continue;
            }

            $event = StreamJson::decode($line);
            $type = $event['type'] ?? '';

            if ($type === 'system' && ($event['subtype'] ?? '') === 'init') {
                $this->assertInit($event);
            } elseif ($type === 'stream_event' && ($event['event']['type'] ?? '') === 'message_start') {
                $this->messageLines = [$line];
                $this->messageId = (string) ($event['event']['message']['id'] ?? '');
            } elseif ($type === 'assistant' || $type === 'stream_event' && ($event['event']['type'] ?? '') === 'message_delta') {
                $flushed = $this->collect($line, $type);

                if ($flushed instanceof StepResponse) {
                    return $flushed;
                }
            } elseif ($type === 'stream_event' && ($event['event']['type'] ?? '') === 'message_stop') {
                return $this->closeMessage();
            } elseif ($type === 'user') {
                $this->recordUserLine($event);
            } elseif ($type === 'result') {
                $this->recordResult($event);

                return $this->messageLines === [] ? null : $this->closeMessage();
            }
        }
    }

    private function collect(string $line, string $type): ?StepResponse
    {
        $id = $type === 'assistant' ? StreamJson::messageId($line) : null;

        if ($id !== null && $this->messageId !== null && $this->messageId !== '' && $id !== $this->messageId) {
            array_unshift($this->held, $line);

            return $this->closeMessage();
        }

        $this->messageId = $id ?? $this->messageId;
        $this->messageLines[] = $line;

        return null;
    }

    private function closeMessage(): StepResponse
    {
        $step = StreamJson::step(...$this->messageLines);
        $this->messages[] = ['id' => $this->messageId, 'tool_use_ids' => array_map(fn (ToolCall $call): string => $call->id, $step->toolCalls)];
        $this->messageLines = [];
        $this->messageId = null;

        return $step;
    }

    private function readToResult(): void
    {
        while ($this->result === null) {
            $line = $this->takeLine();

            if ($line === null) {
                $this->idle();

                continue;
            }

            $event = StreamJson::decode($line);

            if (($event['type'] ?? '') === 'result') {
                $this->recordResult($event);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recordUserLine(array $event): void
    {
        foreach ((array) ($event['message']['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'tool_result' && is_string($block['tool_use_id'] ?? null)) {
                $this->answeredByClaude[$block['tool_use_id']] = true;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function recordResult(array $event): void
    {
        $this->result = $event;

        if (($event['is_error'] ?? false) === true) {
            throw new RuntimeException('Claude Code failed: '.mb_substr((string) ($event['result'] ?? json_encode($event)), 0, 500));
        }
    }

    private function merge(StepResponse $earlier, StepResponse $later): StepResponse
    {
        $later->text = $earlier->text === '' ? $later->text : $earlier->text."\n\n".$later->text;
        $later->usage = $earlier->usage->add($later->usage);

        return $later;
    }

    private function withoutCalls(StepResponse $step): StepResponse
    {
        $step->toolCalls = [];
        $step->finishReason = FinishReason::Stop;

        return $step;
    }

    /**
     * @template T
     *
     * @param  callable(): T|null  $ready
     * @return T
     */
    private function waitFor(callable $ready): mixed
    {
        while (true) {
            $this->consumeLines();

            if (($value = $ready()) !== null) {
                return $value;
            }

            $this->idle();
        }
    }

    private function consumeLines(): void
    {
        while (($line = $this->rawLine()) !== null) {
            $event = StreamJson::decode($line);

            if (($event['type'] ?? '') === 'user') {
                $this->recordUserLine($event);

                continue;
            }

            if (($event['type'] ?? '') === 'result') {
                $this->recordResult($event);
            }

            $this->held[] = $line;
        }
    }

    private function takeLine(): ?string
    {
        return array_shift($this->held) ?? $this->rawLine();
    }

    private function rawLine(): ?string
    {
        $this->pump();

        $newline = strpos($this->buffer, "\n");

        if ($newline === false) {
            return null;
        }

        $line = substr($this->buffer, 0, $newline);
        $this->buffer = substr($this->buffer, $newline + 1);

        return trim($line) === '' ? $this->rawLine() : $line;
    }

    private function pump(): void
    {
        $output = $this->process->latestOutput();

        if ($output !== '') {
            $this->buffer .= $output;
            $this->lastOutputAt = microtime(true);
        }
    }

    private function idle(): void
    {
        $this->rendezvous->accept(self::POLL_SECONDS);

        if (! $this->process->running()) {
            $this->pump();

            if (! str_contains($this->buffer, "\n")) {
                throw new RuntimeException('Claude Code exited before the turn ended: '.mb_substr(trim($this->process->errorOutput()) ?: 'no error output', 0, 500));
            }
        }

        if (microtime(true) - $this->lastOutputAt > self::IDLE_LIMIT_SECONDS) {
            throw new RuntimeException('Claude Code wrote nothing for '.self::IDLE_LIMIT_SECONDS.' seconds.');
        }
    }

    /**
     * @param  array<string, mixed>  $init
     */
    private function assertInit(array $init): void
    {
        $this->init = $init;

        if (($init['apiKeySource'] ?? null) !== 'none') {
            throw new RuntimeException('Claude Code is not signed in with the plan, its key source is '.json_encode($init['apiKeySource'] ?? null));
        }

        $connected = array_filter((array) ($init['mcp_servers'] ?? []), fn (mixed $server): bool => is_array($server) && ($server['name'] ?? null) === 'rela' && ($server['status'] ?? null) === 'connected');

        if ($connected === []) {
            throw new RuntimeException('Claude Code did not connect the rela tool server: '.json_encode($init['mcp_servers'] ?? null));
        }

        $missing = array_diff($this->toolNames, (array) ($init['tools'] ?? []));

        if ($missing !== []) {
            throw new RuntimeException('Claude Code does not offer '.count($missing).' of the rela tools, first '.reset($missing));
        }
    }
}
