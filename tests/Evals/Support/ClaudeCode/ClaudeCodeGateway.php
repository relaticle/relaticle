<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use Generator;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Files\TranscribableAudio;
use Laravel\Ai\Contracts\Gateway\Gateway;
use Laravel\Ai\Contracts\Gateway\StepTextGateway;
use Laravel\Ai\Contracts\Providers\AudioProvider;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Contracts\Providers\TranscriptionProvider;
use Laravel\Ai\Gateway\Anthropic\Concerns\MapsTools;
use Laravel\Ai\Gateway\StepContext;
use Laravel\Ai\Gateway\StepResponse;
use Laravel\Ai\Gateway\TextGenerationOptions;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Responses\AudioResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\ImageResponse;
use Laravel\Ai\Responses\TranscriptionResponse;
use Laravel\Ai\Streaming\Events\StreamStart;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\TextEnd;
use Laravel\Ai\Streaming\Events\TextStart;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use LogicException;
use Throwable;

final class ClaudeCodeGateway implements Gateway, StepTextGateway
{
    use MapsTools;

    private const array EFFORT_LEVELS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /** @var array<string, ClaudeCodeProcess> */
    private static array $live = [];

    /** @var list<ClaudeCodeProcess> */
    private static array $started = [];

    /**
     * @return list<array{input: string, init: array<string, mixed>, messages: list<array{id: string|null, tool_use_ids: list<string>}>, rejected_tool_calls: list<array{id: string, name: string}>, result: array<string, mixed>|null}>
     */
    public static function takeRecords(): array
    {
        $records = array_map(fn (ClaudeCodeProcess $process): array => [
            'input' => $process->firstInput,
            'init' => array_intersect_key($process->init(), array_flip(['model', 'apiKeySource', 'claude_code_version', 'tools', 'mcp_servers'])),
            'messages' => $process->messages(),
            'rejected_tool_calls' => $process->rejected(),
            'result' => $process->result(),
        ], self::$started);

        self::$started = [];

        return $records;
    }

    public static function releaseAll(): void
    {
        foreach (self::$live as $key => $process) {
            $process->release();
            unset(self::$live[$key]);
        }
    }

    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        return $this->step('text:'.md5($model.$instructions), $provider, $model, $instructions, $messages, $tools, $schema, $options);
    }

    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        $response = $this->step($invocationId, $provider, $model, $instructions, $messages, $tools, $schema, $options);

        $messageId = $this->eventId();

        yield (new StreamStart($this->eventId(), $provider->name(), $response->meta->model, time()))->withInvocationId($invocationId);

        if ($response->text !== '') {
            yield (new TextStart($this->eventId(), $messageId, time()))->withInvocationId($invocationId);
            yield (new TextDelta($this->eventId(), $messageId, $response->text, time()))->withInvocationId($invocationId);
        }

        foreach ($response->toolCalls as $toolCall) {
            yield (new ToolCallEvent($this->eventId(), $toolCall, time()))->withInvocationId($invocationId);
        }

        if ($response->text !== '') {
            yield (new TextEnd($this->eventId(), $messageId, time()))->withInvocationId($invocationId);
        }

        return $response;
    }

    /**
     * @param  array<int, Message>  $messages
     * @param  array<int, mixed>  $tools
     * @param  array<string, mixed>|null  $schema
     */
    private function step(string $key, TextProvider $provider, string $model, ?string $instructions, array $messages, array $tools, ?array $schema, ?TextGenerationOptions $options): StepResponse
    {
        if ($schema !== null) {
            throw new LogicException('The Claude Code gateway does not support structured output.');
        }

        $process = self::$live[$key] ?? null;

        try {
            if ($process === null) {
                $process = self::$live[$key] = self::$started[] = ClaudeCodeProcess::start(
                    $model,
                    $this->effortFrom($options),
                    (string) $instructions,
                    $this->firstMessage($messages),
                    $this->mcpTools($tools, $provider),
                );
            } else {
                $process->deliver($this->pendingResults($messages));
            }

            $response = $process->nextStep();
        } catch (Throwable $e) {
            $process?->release();
            unset(self::$live[$key]);

            throw $e;
        }

        if ($response->finishReason !== FinishReason::ToolCalls) {
            $process->release();
            unset(self::$live[$key]);
        }

        return $response;
    }

    /**
     * @param  array<int, Message>  $messages
     */
    private function firstMessage(array $messages): string
    {
        $current = array_pop($messages);

        if (! $current instanceof UserMessage) {
            throw new LogicException('The Claude Code gateway starts a turn from a user message.');
        }

        $history = HistoryTranscript::render($messages);

        return $history === '' ? (string) $current->content : $history."\n\n".$current->content;
    }

    /**
     * @param  array<int, Message>  $messages
     * @return list<ToolResult>
     */
    private function pendingResults(array $messages): array
    {
        $tail = end($messages);

        if (! $tail instanceof ToolResultMessage) {
            throw new LogicException('The Claude Code gateway continues a turn from tool results.');
        }

        return $tail->toolResults->values()->all();
    }

    /**
     * @param  array<int, mixed>  $tools
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    private function mcpTools(array $tools, TextProvider $provider): array
    {
        return array_map(fn (array $tool): array => [
            'name' => $tool['name'],
            'description' => $tool['description'],
            'inputSchema' => $this->withoutEmptyProperties($tool['input_schema']),
        ], $this->mapTools($tools, $provider));
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    private function withoutEmptyProperties(array $schema): array
    {
        return (array) ($schema['properties'] ?? []) === [] ? ['type' => 'object'] : $schema;
    }

    private function effortFrom(?TextGenerationOptions $options): string
    {
        $effort = $options?->providerOptions('anthropic')['output_config']['effort'] ?? config('chat.anthropic_effort');

        return is_string($effort) && in_array($effort, self::EFFORT_LEVELS, true) ? $effort : 'high';
    }

    private function eventId(): string
    {
        return strtolower((string) Str::uuid7());
    }

    public function generateImage(ImageProvider $provider, string $model, string $prompt, array $attachments = [], ?string $size = null, ?string $quality = null, ?int $timeout = null, array $providerOptions = []): ImageResponse
    {
        throw new LogicException('Claude Code does not support image generation.');
    }

    public function generateAudio(AudioProvider $provider, string $model, string $text, string $voice, ?string $instructions = null, int $timeout = 30, array $providerOptions = []): AudioResponse
    {
        throw new LogicException('Claude Code does not support audio generation.');
    }

    public function generateTranscription(TranscriptionProvider $provider, string $model, TranscribableAudio $audio, ?string $language = null, bool $diarize = false, int $timeout = 30, array $providerOptions = []): TranscriptionResponse
    {
        throw new LogicException('Claude Code does not support transcription.');
    }

    public function generateEmbeddings(EmbeddingProvider $provider, string $model, array $inputs, int $dimensions, int $timeout = 30, array $providerOptions = []): EmbeddingsResponse
    {
        throw new LogicException('Claude Code does not support embeddings.');
    }
}
