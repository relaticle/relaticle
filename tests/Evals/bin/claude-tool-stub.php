<?php

declare(strict_types=1);

[, $schemaFile, $socketPath] = $argv;

$tools = json_decode((string) file_get_contents($schemaFile), true, flags: JSON_THROW_ON_ERROR);

$reply = static function (mixed $id, array $result): void {
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
};

$toolResult = static fn (string $text, bool $isError): array => ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError];

$inflight = [];
$inputBuffer = '';
$inputOpen = true;

$handle = static function (array $request) use (&$inflight, $reply, $toolResult, $tools, $socketPath): void {
    if (! isset($request['id'])) {
        return;
    }

    if (($request['method'] ?? '') !== 'tools/call') {
        $reply($request['id'], match ($request['method'] ?? '') {
            'initialize' => [
                'protocolVersion' => $request['params']['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => ['name' => 'rela', 'version' => '1'],
            ],
            'tools/list' => ['tools' => $tools],
            default => [],
        });

        return;
    }

    $connection = @stream_socket_client('unix://'.$socketPath, $code, $message, 10);

    if ($connection === false) {
        $reply($request['id'], $toolResult("The eval harness is gone: {$message}", true));

        return;
    }

    fwrite($connection, json_encode([
        'id' => $request['params']['_meta']['claudecode/toolUseId'] ?? null,
        'name' => (string) $request['params']['name'],
        'arguments' => (array) ($request['params']['arguments'] ?? []),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");

    $inflight[(int) $connection] = ['connection' => $connection, 'id' => $request['id'], 'buffer' => ''];
};

while ($inputOpen || $inflight !== []) {
    $read = array_column($inflight, 'connection');

    if ($inputOpen) {
        $read[] = STDIN;
    }

    $write = $except = null;

    if (stream_select($read, $write, $except, null) === false) {
        break;
    }

    foreach ($read as $stream) {
        $chunk = (string) fread($stream, 65536);

        if ($stream === STDIN) {
            $inputOpen = ! feof(STDIN);
            $inputBuffer .= $chunk;

            while (($newline = strpos($inputBuffer, "\n")) !== false) {
                $request = json_decode(substr($inputBuffer, 0, $newline), true);
                $inputBuffer = substr($inputBuffer, $newline + 1);

                if (is_array($request)) {
                    $handle($request);
                }
            }

            continue;
        }

        $key = (int) $stream;
        $inflight[$key]['buffer'] .= $chunk;

        if (! str_contains($inflight[$key]['buffer'], "\n") && ! feof($stream)) {
            continue;
        }

        $answer = json_decode(trim($inflight[$key]['buffer']), true);
        $reply($inflight[$key]['id'], is_array($answer)
            ? $toolResult((string) ($answer['text'] ?? ''), (bool) ($answer['is_error'] ?? false))
            : $toolResult('The eval harness closed without an answer.', true));
        fclose($stream);
        unset($inflight[$key]);
    }
}
