<?php

declare(strict_types=1);

namespace Tests\Evals\Support\ClaudeCode;

use RuntimeException;

final class ToolRendezvous
{
    /** @var list<array{connection: resource, id: string|null, name: string, arguments: array<string, mixed>}> */
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
     * @return array{id: string|null, name: string, arguments: array<string, mixed>}|null
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

        $request = [
            'id' => is_string($request['id'] ?? null) ? $request['id'] : null,
            'name' => $request['name'],
            'arguments' => is_array($request['arguments'] ?? null) ? $request['arguments'] : [],
        ];
        $this->waiting[] = ['connection' => $connection] + $request;

        return $request;
    }

    /**
     * @return list<array{id: string|null, name: string, arguments: array<string, mixed>}>
     */
    public function waiting(): array
    {
        return array_map(fn (array $call): array => ['id' => $call['id'], 'name' => $call['name'], 'arguments' => $call['arguments']], $this->waiting);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function indexOf(string $id, string $name, array $arguments): ?int
    {
        foreach ($this->waiting as $index => $call) {
            if ($call['id'] === $id) {
                return $index;
            }
        }

        foreach ($this->waiting as $index => $call) {
            if ($call['id'] === null && $call['name'] === $name && self::canonical($call['arguments']) === self::canonical($arguments)) {
                return $index;
            }
        }

        return null;
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
