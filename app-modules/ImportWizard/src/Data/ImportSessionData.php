<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Data;

use Illuminate\Support\Facades\Cache;
use Relaticle\ImportWizard\Enums\PreviewStatus;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class ImportSessionData extends Data
{
    private const int DEFAULT_TTL_HOURS = 24;

    public function __construct(
        public string $teamId,
        public string $inputHash,
        public int $total,
        public int $processed = 0,
        public int $creates = 0,
        public int $updates = 0,
        public ?int $heartbeat = null,
        public ?string $error = null,
    ) {}

    public static function find(string $sessionId): ?self
    {
        $data = Cache::get(self::cacheKey($sessionId));

        return $data !== null ? self::from($data) : null;
    }

    public function status(): PreviewStatus
    {
        return match (true) {
            $this->error !== null => PreviewStatus::Failed,
            $this->processed >= $this->total => PreviewStatus::Ready,
            default => PreviewStatus::Processing,
        };
    }

    public function isHeartbeatStale(int $thresholdSeconds = 30): bool
    {
        return $this->heartbeat !== null
            && (int) now()->timestamp - $this->heartbeat > $thresholdSeconds;
    }

    public function refresh(string $sessionId): void
    {
        Cache::put(
            self::cacheKey($sessionId),
            [...$this->toArray(), 'heartbeat' => (int) now()->timestamp],
            now()->addHours(self::ttlHours())
        );
    }

    /** @param array<string, mixed> $changes */
    public static function update(string $sessionId, array $changes): void
    {
        $data = self::find($sessionId);

        if (! $data instanceof self) {
            return;
        }

        Cache::put(
            self::cacheKey($sessionId),
            [...$data->toArray(), ...$changes],
            now()->addHours(self::ttlHours())
        );
    }

    public function save(string $sessionId): void
    {
        Cache::put(self::cacheKey($sessionId), $this->toArray(), now()->addHours(self::ttlHours()));
    }

    public static function forget(string $sessionId): void
    {
        Cache::forget(self::cacheKey($sessionId));
    }

    public static function cacheKey(string $sessionId): string
    {
        return "import:{$sessionId}";
    }

    public static function ttlHours(): int
    {
        return (int) config('import-wizard.session_ttl_hours', self::DEFAULT_TTL_HOURS);
    }
}
