<?php

declare(strict_types=1);

namespace Tests\Helpers;

use RuntimeException;

final class PestTiaRuntime
{
    private const string STORAGE_ENVIRONMENT_KEY = 'RELATICLE_PEST_TIA_STORAGE';

    private const string SHARED_ENVIRONMENT_KEY = 'RELATICLE_PEST_TIA_SHARED';

    /** @var list<string> */
    private const array COMPLETE_RUN_FLAGS = [
        '--parallel',
        '-p',
        '--compact',
        '--profile',
        '--fresh',
        '--refetch',
        '--filtered',
        '--locally',
        '--baselined',
        '--tia',
        '--no-colors',
        '--testdox',
        '--teamcity',
        '--stderr',
        '--display-incomplete',
        '--display-skipped',
        '--display-deprecations',
        '--display-phpunit-deprecations',
        '--display-errors',
        '--display-notices',
        '--display-warnings',
        '--display-all-issues',
    ];

    /** @var list<string> */
    private const array COMPLETE_RUN_VALUE_FLAGS = [
        '--colors',
        '--columns',
    ];

    /**
     * @param  list<string>  $arguments
     */
    public static function configure(string $projectRoot, array $arguments): void
    {
        $projectRoot = self::realDirectory($projectRoot, 'project root');
        $inheritedStorage = getenv(self::STORAGE_ENVIRONMENT_KEY);
        $inheritedShared = getenv(self::SHARED_ENVIRONMENT_KEY);

        if (is_string($inheritedStorage) && $inheritedStorage !== '' && in_array($inheritedShared, ['0', '1'], true)) {
            $storageDirectory = $inheritedStorage;
            $usesSharedState = $inheritedShared === '1';
        } else {
            $usesSharedState = self::usesSharedState($arguments);
            $storageDirectory = self::storageDirectory($projectRoot, $usesSharedState);

            self::export(self::STORAGE_ENVIRONMENT_KEY, $storageDirectory);
            self::export(self::SHARED_ENVIRONMENT_KEY, $usesSharedState ? '1' : '0');
        }

        if ($usesSharedState) {
            self::assertCoverageDriverAvailable();

            $gitCommonDirectory = self::gitCommonDirectory($projectRoot);
            TiaRunLock::acquire(
                $gitCommonDirectory.'/relaticle-pest-tia.lock',
                $projectRoot,
            );
        }

        pest()->tia()->directory($storageDirectory)->locally();
    }

    public static function storageDirectory(string $projectRoot, bool $usesSharedState): string
    {
        $projectRoot = self::realDirectory($projectRoot, 'project root');

        if (! $usesSharedState) {
            return $projectRoot.'/storage/framework/testing/pest-tia';
        }

        return self::gitCommonDirectory($projectRoot).'/relaticle/pest-tia';
    }

    /**
     * @param  list<string>  $arguments
     */
    public static function usesSharedState(array $arguments): bool
    {
        $argumentCount = count($arguments);

        for ($index = 0; $index < $argumentCount; $index++) {
            $argument = $arguments[$index];

            if (in_array($argument, self::COMPLETE_RUN_FLAGS, true)) {
                continue;
            }

            if ($argument === '--parallel' || str_starts_with($argument, '--parallel=')) {
                continue;
            }

            $valueFlag = self::matchingValueFlag($argument);

            if ($valueFlag === null) {
                return false;
            }

            if ($argument === $valueFlag) {
                if (! isset($arguments[$index + 1])) {
                    return false;
                }

                $index++;
            }
        }

        return true;
    }

    private static function matchingValueFlag(string $argument): ?string
    {
        foreach (self::COMPLETE_RUN_VALUE_FLAGS as $flag) {
            if ($argument === $flag || str_starts_with($argument, $flag.'=')) {
                return $flag;
            }
        }

        return null;
    }

    private static function gitCommonDirectory(string $projectRoot): string
    {
        $dotGitPath = $projectRoot.'/.git';

        if (is_dir($dotGitPath)) {
            return self::realDirectory($dotGitPath, 'Git common directory');
        }

        if (! is_file($dotGitPath)) {
            throw new RuntimeException("Unable to find Git metadata at {$dotGitPath}.");
        }

        $dotGitContents = @file_get_contents($dotGitPath);

        if (! is_string($dotGitContents) || preg_match('/^gitdir:\s*(.+)$/m', $dotGitContents, $matches) !== 1) {
            throw new RuntimeException("Unable to parse linked-worktree metadata at {$dotGitPath}.");
        }

        $gitDirectoryPath = trim($matches[1]);

        if (! self::isAbsolutePath($gitDirectoryPath)) {
            $gitDirectoryPath = $projectRoot.'/'.$gitDirectoryPath;
        }

        $gitDirectory = self::realDirectory($gitDirectoryPath, 'worktree Git directory');
        $commonDirectoryPath = $gitDirectory.'/commondir';

        if (! is_file($commonDirectoryPath)) {
            return $gitDirectory;
        }

        $commonDirectory = trim((string) @file_get_contents($commonDirectoryPath));

        if ($commonDirectory === '') {
            throw new RuntimeException("Git common-directory metadata is empty at {$commonDirectoryPath}.");
        }

        if (! self::isAbsolutePath($commonDirectory)) {
            $commonDirectory = $gitDirectory.'/'.$commonDirectory;
        }

        return self::realDirectory($commonDirectory, 'Git common directory');
    }

    private static function realDirectory(string $path, string $description): string
    {
        $realPath = @realpath($path);

        if ($realPath === false || ! is_dir($realPath)) {
            throw new RuntimeException("Unable to resolve the {$description} at {$path}.");
        }

        return rtrim($realPath, '/\\');
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[a-z]:[\\\\\/]/i', $path) === 1;
    }

    private static function assertCoverageDriverAvailable(): void
    {
        $pcovEnabled = extension_loaded('pcov')
            && filter_var(ini_get('pcov.enabled'), FILTER_VALIDATE_BOOL);

        if ($pcovEnabled || self::xdebugCoverageEnabled()) {
            return;
        }

        throw new RuntimeException(
            'Shared Pest TIA recording requires enabled PCOV or Xdebug coverage mode. '
            .'Enable PCOV or run with XDEBUG_MODE=coverage.',
        );
    }

    private static function xdebugCoverageEnabled(): bool
    {
        if (! extension_loaded('xdebug')) {
            return false;
        }

        $modes = @xdebug_info('mode');

        if (is_array($modes) && in_array('coverage', $modes, true)) {
            return true;
        }

        $environmentMode = getenv('XDEBUG_MODE');

        if (! is_string($environmentMode)) {
            return false;
        }

        return in_array('coverage', array_map('trim', explode(',', strtolower($environmentMode))), true);
    }

    private static function export(string $key, string $value): void
    {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}
