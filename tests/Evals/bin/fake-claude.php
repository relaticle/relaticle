<?php

declare(strict_types=1);

$scenarioPath = $argv[1];
$scenario = json_decode((string) file_get_contents($scenarioPath), true, flags: JSON_THROW_ON_ERROR);
$arguments = array_slice($argv, 2);

$after = static function (string $flag) use ($arguments): array {
    $start = array_search($flag, $arguments, true);
    $values = [];

    for ($i = (int) $start + 1; $start !== false && $i < count($arguments) && ! str_starts_with($arguments[$i], '--'); $i++) {
        $values[] = $arguments[$i];
    }

    return $values;
};

$mcp = json_decode((string) file_get_contents($after('--mcp-config')[0]), true);
$socketPath = $mcp['mcpServers']['rela']['args'][2];
$firstInput = (string) fgets(STDIN);

$record = [
    'pid' => getmypid(),
    'arguments' => $arguments,
    'claudecode_set' => getenv('CLAUDECODE') !== false,
    'api_key_set' => getenv('ANTHROPIC_API_KEY') !== false,
    'tool_search' => getenv('ENABLE_TOOL_SEARCH'),
    'first_input' => $firstInput,
    'replies' => [],
];
$save = static function () use ($scenarioPath, &$record): void {
    file_put_contents($scenarioPath.'.record', json_encode($record, JSON_THROW_ON_ERROR));
};
$save();

$emit = static function (array $event): void {
    fwrite(STDOUT, json_encode($event, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
};

$connections = [];
$wait = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

foreach ($scenario['steps'] as $step) {
    switch ($step['do']) {
        case 'init':
            $emit(['type' => 'system', 'subtype' => 'init', 'model' => $step['model'] ?? 'claude-sonnet-5', 'tools' => $step['tools'] ?? $after('--allowedTools'), 'mcp_servers' => $step['mcp_servers'] ?? [['name' => 'rela', 'status' => 'connected']]]
                + (array_key_exists('apiKeySource', $step) ? ['apiKeySource' => $step['apiKeySource']] : ['apiKeySource' => 'none']));

            break;
        case 'message':
            $model = $step['model'] ?? 'claude-sonnet-5';
            $usage = $step['usage'] ?? ['input_tokens' => 2, 'cache_creation_input_tokens' => 10, 'cache_read_input_tokens' => 20, 'output_tokens' => 4];
            $emit(['type' => 'stream_event', 'event' => ['type' => 'message_start', 'message' => ['id' => $step['id'], 'model' => $model]]]);

            foreach ($step['blocks'] as $block) {
                $emit(['type' => 'stream_event', 'event' => ['type' => 'content_block_start', 'content_block' => ['type' => $block['type']]]]);
                $emit(['type' => 'assistant', 'message' => ['id' => $step['id'], 'model' => $model, 'content' => [$block], 'usage' => $usage]]);
            }

            $emit(['type' => 'stream_event', 'event' => ['type' => 'message_delta', 'delta' => ['stop_reason' => $step['stop'] ?? 'end_turn'], 'usage' => ['output_tokens' => $step['output_tokens'] ?? 9]]]);
            $emit(['type' => 'stream_event', 'event' => ['type' => 'message_stop']]);

            break;
        case 'connect':
            $connection = stream_socket_client('unix://'.$socketPath, $code, $message, 10);
            fwrite($connection, json_encode(['id' => $step['id'] ?? null, 'name' => $step['name'], 'arguments' => $step['arguments'] ?? []], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            $connections[$step['key']] = $connection;

            break;
        case 'await':
            $pending = array_intersect_key($connections, array_flip($step['keys']));

            while ($pending !== []) {
                $read = array_values($pending);
                $write = $except = null;
                stream_select($read, $write, $except, 30);

                foreach ($read as $stream) {
                    $key = array_search($stream, $pending, true);
                    $answer = json_decode(trim((string) fgets($stream)), true);
                    $record['replies'][] = ['key' => $key] + (array) $answer;
                    $save();
                    unset($pending[$key]);
                }
            }

            break;
        case 'user_error':
            $emit(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => $step['id'], 'is_error' => true, 'content' => 'permission denied']]]]);

            break;
        case 'user_ok':
            $emit(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'tool_use_id' => $step['id'], 'content' => 'ok']]]]);

            break;
        case 'pause':
            $read = [$wait[0]];
            $write = $except = null;
            stream_select($read, $write, $except, (int) $step['seconds'], (int) (fmod((float) $step['seconds'], 1) * 1_000_000));

            break;
        case 'result':
            $emit(['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'done', 'total_cost_usd' => $step['cost'] ?? 0.01, 'usage' => $step['usage'] ?? ['input_tokens' => 3, 'cache_read_input_tokens' => 30, 'cache_creation_input_tokens' => 15, 'output_tokens' => 11]]);

            break;
        case 'result_error':
            $emit(['type' => 'result', 'subtype' => 'error', 'is_error' => true, 'result' => $step['text']]);

            break;
        case 'exit':
            exit($step['code'] ?? 0);
        case 'hang':
            $read = [$wait[0]];
            $write = $except = null;
            stream_select($read, $write, $except, 600);

            break;
    }
}
