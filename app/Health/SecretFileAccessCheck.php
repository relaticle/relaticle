<?php

declare(strict_types=1);

namespace App\Health;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

final class SecretFileAccessCheck extends Check
{
    private const int MAX_SCAN_AGE_MINUTES = 30;

    private const int ALERT_WINDOW_HOURS = 24;

    public function run(): Result
    {
        $result = Result::make();
        $status = $this->readStatus();

        if ($status === null) {
            return $result->failed('The secret file scan status is missing or unreadable.');
        }

        $scannedAt = now()->setTimestamp($status['scanned_at']);

        if ($scannedAt->isBefore(now()->subMinutes(self::MAX_SCAN_AGE_MINUTES))) {
            return $result->failed("The secret file scan last ran {$scannedAt->diffForHumans()}.");
        }

        $cutoff = now()->subHours(self::ALERT_WINDOW_HOURS)->timestamp;
        $recent = array_values(array_filter($status['unexpected'], fn (array $read): bool => $read['at'] > $cutoff));

        if ($recent === []) {
            return $result->ok()->shortSummary('no unexpected reads');
        }

        usort($recent, fn (array $a, array $b): int => $b['at'] <=> $a['at']);

        $count = count($recent);
        $noun = $count === 1 ? 'read of a production secret file' : 'reads of production secret files';

        return $result->failed("{$count} unexpected {$noun} in the last ".self::ALERT_WINDOW_HOURS." hours. Newest: {$recent[0]['exe']} read {$recent[0]['path']}.");
    }

    /**
     * @return array{scanned_at: int, unexpected: list<array{at: int, exe: string, auid: string, path: string}>}|null
     */
    private function readStatus(): ?array
    {
        $path = config('health.secret_access_status_path');

        if (! is_string($path) || ! is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded) || ! is_int($decoded['scanned_at'] ?? null) || ! is_array($decoded['unexpected'] ?? null)) {
            return null;
        }

        $reads = [];

        foreach ($decoded['unexpected'] as $read) {
            if (! is_array($read) || ! is_int($read['at'] ?? null)) {
                return null;
            }

            $reads[] = [
                'at' => $read['at'],
                'exe' => (string) ($read['exe'] ?? 'unknown'),
                'auid' => (string) ($read['auid'] ?? 'unknown'),
                'path' => (string) ($read['path'] ?? 'unknown'),
            ];
        }

        return ['scanned_at' => $decoded['scanned_at'], 'unexpected' => $reads];
    }
}
