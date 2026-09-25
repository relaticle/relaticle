<?php

declare(strict_types=1);

namespace Tests\Helpers;

use RuntimeException;

final class TiaRunLock
{
    private const string TOKEN_ENVIRONMENT_KEY = 'RELATICLE_PEST_TIA_LOCK_TOKEN';

    /** @var resource|null */
    private static $handle = null;

    public static function acquire(
        string $lockPath,
        string $workspace,
        int $timeoutSeconds = 900,
    ): void {
        if (self::$handle !== null || self::isParaTestWorker()) {
            return;
        }

        self::ensureDirectoryExists(dirname($lockPath));

        $handle = @fopen($lockPath, 'c+');

        if (! is_resource($handle)) {
            throw new RuntimeException("Unable to open the Pest TIA lock at {$lockPath}.");
        }

        $hasLock = flock($handle, LOCK_EX | LOCK_NB);
        $inheritedToken = self::inheritedToken();

        if (! $hasLock && $inheritedToken !== null && self::metadataTokenMatches($lockPath, $inheritedToken)) {
            $hasLock = flock($handle, LOCK_EX | LOCK_NB);

            if (! $hasLock) {
                fclose($handle);

                return;
            }
        }

        $deadline = microtime(true) + max(0, $timeoutSeconds);

        while (! $hasLock) {
            if (microtime(true) >= $deadline) {
                $metadata = self::readMetadata($lockPath);
                fclose($handle);

                $holderPid = $metadata['pid'] ?? 'unknown';
                $holderWorkspace = $metadata['workspace'] ?? 'unknown';

                throw new RuntimeException(
                    "Timed out waiting for the Pest TIA lock. Holder PID: {$holderPid}. "
                    ."Holder workspace: {$holderWorkspace}. Lock: {$lockPath}.",
                );
            }

            usleep(50_000);
            $hasLock = flock($handle, LOCK_EX | LOCK_NB);
        }

        $token = bin2hex(random_bytes(32));
        $metadata = json_encode([
            'token' => $token,
            'pid' => getmypid(),
            'workspace' => $workspace,
            'acquired_at' => gmdate(DATE_ATOM),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        rewind($handle);

        if (! ftruncate($handle, 0) || fwrite($handle, $metadata) === false || ! fflush($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);

            throw new RuntimeException("Unable to write Pest TIA lock metadata at {$lockPath}.");
        }

        self::exportToken($token);
        self::$handle = $handle;
    }

    private static function isParaTestWorker(): bool
    {
        $value = $_SERVER['PARATEST'] ?? $_ENV['PARATEST'] ?? getenv('PARATEST');

        return (int) $value === 1;
    }

    private static function ensureDirectoryExists(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (! @mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create the Pest TIA lock directory at {$directory}.");
        }
    }

    private static function inheritedToken(): ?string
    {
        $token = getenv(self::TOKEN_ENVIRONMENT_KEY);

        return is_string($token) && $token !== '' ? $token : null;
    }

    private static function metadataTokenMatches(string $lockPath, string $token): bool
    {
        $metadata = self::readMetadata($lockPath);
        $metadataToken = $metadata['token'] ?? null;

        return is_string($metadataToken) && hash_equals($metadataToken, $token);
    }

    /** @return array<string, mixed> */
    private static function readMetadata(string $lockPath): array
    {
        $contents = @file_get_contents($lockPath);

        if (! is_string($contents) || $contents === '') {
            return [];
        }

        $metadata = json_decode($contents, true);

        return is_array($metadata) ? $metadata : [];
    }

    private static function exportToken(string $token): void
    {
        putenv(self::TOKEN_ENVIRONMENT_KEY.'='.$token);
        $_ENV[self::TOKEN_ENVIRONMENT_KEY] = $token;
        $_SERVER[self::TOKEN_ENVIRONMENT_KEY] = $token;
    }
}
