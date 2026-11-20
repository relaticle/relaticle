<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Sleep;

function unreachableRedisConnection(): string
{
    config(['database.redis.unreachable' => [
        ...config('database.redis.default'),
        'host' => '127.0.0.1',
        'port' => 1,
    ]]);

    return 'unreachable';
}

it('retries a refused redis connection with backoff before giving up', function (): void {
    Sleep::fake();
    $connection = unreachableRedisConnection();

    expect(fn () => Redis::connection($connection)->ping())->toThrow(RedisException::class);

    Sleep::assertSequence([
        Sleep::for(200)->milliseconds(),
        Sleep::for(400)->milliseconds(),
        Sleep::for(800)->milliseconds(),
        Sleep::for(1000)->milliseconds(),
    ]);
});
