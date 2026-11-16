<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use RuntimeException;

final class ToolRendezvous
{
    /** @var list<array{connection: resource, name: string, arguments: array<string, mixed>}> */
    private array $waiting = [];

    /**
     * @param  resource  $server
     */
    private function __construct(private $server, private readonly string $path) {}

    public static function listen(string $directory): self
    {
        $path = $directory.'/s';
        $server = @stream_socket_server('unix://'.$path, $code, $message);

        if ($server === false) {
            throw new RuntimeException("The tool rendezvous cannot listen on {$path}: {$message}");
        }

        return new self($server, $path);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @return array{name: string, arguments: array<string, mixed>}|null
     */
    public function accept(float $seconds): ?array
    {
        $read = [$this->server];
        $write = $except = null;

        if (! is_resource($this->server) || (int) stream_select($read, $write, $except, (int) $seconds, (int) (fmod($seconds, 1) * 1_000_000)) === 0) {
            return null;
        }

        $connection = @stream_socket_accept($this->server, 0);

        if ($connection === false) {
            return null;
        }

        stream_set_timeout($connection, 10);
        $request = json_decode((string) stream_get_line($connection, 64 * 1024 * 1024, "\n"), true);

        if (! is_array($request) || ! is_string($request['name'] ?? null)) {
            fclose($connection);

            return null;
        }

        $request = ['name' => $request['name'], 'arguments' => is_array($request['arguments'] ?? null) ? $request['arguments'] : []];
        $this->waiting[] = ['connection' => $connection] + $request;

        return $request;
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>}>
     */
    public function waiting(): array
    {
        return array_map(fn (array $call): array => ['name' => $call['name'], 'arguments' => $call['arguments']], $this->waiting);
    }

    public function reply(string $text, bool $isError, int $index = 0): void
    {
        $call = $this->waiting[$index] ?? throw new RuntimeException('No tool call is waiting at that position.');

        @fwrite($call['connection'], json_encode(['text' => $text, 'is_error' => $isError], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        fclose($call['connection']);

        array_splice($this->waiting, $index, 1);
    }

    public function close(): void
    {
        foreach ($this->waiting as $call) {
            @fclose($call['connection']);
        }

        $this->waiting = [];

        if (is_resource($this->server)) {
            fclose($this->server);
        }

        @unlink($this->path);
    }
}
