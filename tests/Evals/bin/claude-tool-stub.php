<?php

declare(strict_types=1);

[, $schemaFile, $socketPath] = $argv;

$tools = json_decode((string) file_get_contents($schemaFile), true, flags: JSON_THROW_ON_ERROR);

$reply = static function (mixed $id, array $result): void {
    fwrite(STDOUT, json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
};

$call = static function (string $name, array $arguments) use ($socketPath): array {
    $connection = stream_socket_client('unix://'.$socketPath, $code, $message, 10);

    if ($connection === false) {
        return ['text' => "The eval harness is gone: {$message}", 'is_error' => true];
    }

    fwrite($connection, json_encode(['name' => $name, 'arguments' => $arguments], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    stream_set_timeout($connection, 3600);
    $answer = json_decode((string) stream_get_line($connection, 64 * 1024 * 1024, "\n"), true);
    fclose($connection);

    return is_array($answer) ? $answer : ['text' => 'The eval harness closed without an answer.', 'is_error' => true];
};

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);

    if (! is_array($request) || ! isset($request['id'])) {
        continue;
    }

    match ($request['method'] ?? '') {
        'initialize' => $reply($request['id'], [
            'protocolVersion' => $request['params']['protocolVersion'] ?? '2025-06-18',
            'capabilities' => ['tools' => (object) []],
            'serverInfo' => ['name' => 'rela', 'version' => '1'],
        ]),
        'tools/list' => $reply($request['id'], ['tools' => $tools]),
        'tools/call' => $reply($request['id'], (static function (array $answer): array {
            return ['content' => [['type' => 'text', 'text' => (string) $answer['text']]], 'isError' => (bool) ($answer['is_error'] ?? false)];
        })($call((string) $request['params']['name'], (array) ($request['params']['arguments'] ?? [])))),
        default => $reply($request['id'], []),
    };
}
