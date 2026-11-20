<?php

declare(strict_types=1);

namespace App\Support\Redis;

use Illuminate\Contracts\Redis\Connector;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use RedisException;
use Throwable;

final readonly class RetryingPhpRedisConnector implements Connector
{
    public function __construct(private PhpRedisConnector $connector = new PhpRedisConnector) {}

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options
     */
    public function connect(array $config, array $options): Connection
    {
        $retries = (int) ($config['max_retries'] ?? 0);
        $base = (int) ($config['backoff_base'] ?? 0);
        $cap = (int) ($config['backoff_cap'] ?? $base);

        // phpredis applies max_retries only to reconnects; a restarting server refuses the first connect too.
        return retry(
            $retries + 1,
            fn (): Connection => $this->connector->connect($config, $options),
            fn (int $attempt): int => min($cap, $base * 2 ** $attempt),
            fn (Throwable $exception): bool => $exception instanceof RedisException,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $clusterOptions
     * @param  array<string, mixed>  $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): Connection
    {
        return $this->connector->connectToCluster($config, $clusterOptions, $options);
    }
}
