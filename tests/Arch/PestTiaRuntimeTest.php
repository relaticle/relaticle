<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Tests\Helpers\PestTiaRuntime;
use Tests\Helpers\TiaRunLock;

mutates(PestTiaRuntime::class, TiaRunLock::class);

it('shares TIA state for complete local invocations', function (array $arguments): void {
    expect(PestTiaRuntime::usesSharedState($arguments))->toBeTrue();
})->with([
    'bare run' => [[]],
    'parallel run' => [['--parallel']],
    'short parallel flag' => [['-p']],
    'compact output' => [['--compact']],
    'profile output' => [['--profile']],
    'forced fresh graph' => [['--fresh']],
    'explicit local TIA' => [['--tia', '--locally']],
    'colored output value' => [['--colors=always']],
]);

it('isolates partial or non-recording invocations', function (array $arguments): void {
    expect(PestTiaRuntime::usesSharedState($arguments))->toBeFalse();
})->with([
    'test path' => [['tests/Arch']],
    'absolute path' => [['/tmp/ExampleTest.php']],
    'filter' => [['--filter=example']],
    'separate filter value' => [['--filter', 'example']],
    'group' => [['--group=fast']],
    'excluded group' => [['--exclude-group=slow']],
    'testsuite' => [['--testsuite=Arch']],
    'excluded testsuite' => [['--exclude-testsuite=Browser']],
    'dirty tests' => [['--dirty']],
    'todo tests' => [['--todo']],
    'CI run' => [['--ci']],
    'TIA disabled' => [['--no-tia']],
    'type coverage' => [['--type-coverage']],
    'mutation run' => [['--mutate']],
    'shard run' => [['--shard=1/5']],
    'shard refresh' => [['--update-shards']],
    'help' => [['--help']],
    'version' => [['--version']],
    'test listing' => [['--list-tests']],
    'coverage report' => [['--coverage']],
    'unknown option' => [['--future-selection-mode']],
]);

it('queues a second process until the shared lock is released', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-lock-'.bin2hex(random_bytes(8));
    mkdir($temporaryDirectory, 0777, true);
    $lockPath = $temporaryDirectory.'/tia.lock';
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $script = <<<'PHP'
require $argv[1];
Tests\Helpers\TiaRunLock::acquire($argv[2], $argv[3], 5);
fwrite(STDOUT, "acquired\n");
fflush(STDOUT);
usleep((int) $argv[4]);
PHP;

    $holder = new Process(
        [PHP_BINARY, '-r', $script, $autoloadPath, $lockPath, 'holder-workspace', '600000'],
        env: ['PARATEST' => false],
    );
    $contender = new Process(
        [PHP_BINARY, '-r', $script, $autoloadPath, $lockPath, 'contender-workspace', '0'],
        env: ['PARATEST' => false],
    );

    try {
        $holder->start();

        expect($holder->waitUntil(
            static fn (string $type, string $output): bool => str_contains($output, 'acquired'),
        ))->toBeTrue();

        $contender->start();
        usleep(150_000);

        expect($contender->isRunning())->toBeTrue();

        $holder->wait();
        $contender->wait();

        expect($holder->isSuccessful())->toBeTrue()
            ->and($contender->isSuccessful())->toBeTrue()
            ->and($contender->getOutput())->toContain('acquired');
    } finally {
        $holder->stop(0.1);
        $contender->stop(0.1);
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('reports lock holder details after the timeout', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-timeout-'.bin2hex(random_bytes(8));
    mkdir($temporaryDirectory, 0777, true);
    $lockPath = $temporaryDirectory.'/tia.lock';
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $holderScript = <<<'PHP'
require $argv[1];
Tests\Helpers\TiaRunLock::acquire($argv[2], 'holder-workspace', 5);
fwrite(STDOUT, "acquired\n");
fflush(STDOUT);
sleep(3);
PHP;
    $contenderScript = <<<'PHP'
require $argv[1];
Tests\Helpers\TiaRunLock::acquire($argv[2], 'contender-workspace', 1);
PHP;

    $holder = new Process(
        [PHP_BINARY, '-r', $holderScript, $autoloadPath, $lockPath],
        env: ['PARATEST' => false],
    );
    $contender = new Process(
        [PHP_BINARY, '-r', $contenderScript, $autoloadPath, $lockPath],
        env: ['PARATEST' => false],
    );

    try {
        $holder->start();

        expect($holder->waitUntil(
            static fn (string $type, string $output): bool => str_contains($output, 'acquired'),
        ))->toBeTrue();

        $contender->run();
        $failureOutput = $contender->getErrorOutput().$contender->getOutput();

        expect($contender->isSuccessful())->toBeFalse()
            ->and($failureOutput)->toContain('holder-workspace')
            ->and($failureOutput)->toContain('PID')
            ->and($failureOutput)->toContain($lockPath);
    } finally {
        $holder->stop(0.1);
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('lets a restarted child inherit the active lock', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-restart-'.bin2hex(random_bytes(8));
    mkdir($temporaryDirectory, 0777, true);
    $lockPath = $temporaryDirectory.'/tia.lock';
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $holderScript = <<<'PHP'
require $argv[1];
Tests\Helpers\TiaRunLock::acquire($argv[2], 'holder-workspace', 5);
fwrite(STDOUT, "acquired\n");
fflush(STDOUT);
sleep(3);
PHP;
    $childScript = <<<'PHP'
require $argv[1];
Tests\Helpers\TiaRunLock::acquire($argv[2], 'restarted-child', 1);
fwrite(STDOUT, "inherited\n");
PHP;

    $holder = new Process(
        [PHP_BINARY, '-r', $holderScript, $autoloadPath, $lockPath],
        env: ['PARATEST' => false],
    );

    try {
        $holder->start();

        expect($holder->waitUntil(
            static fn (string $type, string $output): bool => str_contains($output, 'acquired'),
        ))->toBeTrue();

        $metadata = json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR);
        $child = new Process(
            [PHP_BINARY, '-r', $childScript, $autoloadPath, $lockPath],
            env: [
                'RELATICLE_PEST_TIA_LOCK_TOKEN' => $metadata['token'],
                'PARATEST' => false,
            ],
        );
        $child->run();

        expect($child->isSuccessful())->toBeTrue()
            ->and($child->getOutput())->toContain('inherited');
    } finally {
        $holder->stop(0.1);
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('lets a ParaTest worker use the lock owned by its parent', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-worker-'.bin2hex(random_bytes(8));
    mkdir($temporaryDirectory, 0777, true);
    $lockPath = $temporaryDirectory.'/tia.lock';
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $script = <<<'PHP'
require $argv[1];
Tests\Helpers\TiaRunLock::acquire($argv[2], 'worker', 1);
fwrite(STDOUT, "worker-bypassed\n");
PHP;
    $worker = new Process(
        [PHP_BINARY, '-r', $script, $autoloadPath, $lockPath],
        env: ['PARATEST' => '1'],
    );

    try {
        $worker->run();

        expect($worker->isSuccessful())->toBeTrue()
            ->and($worker->getOutput())->toContain('worker-bypassed')
            ->and(file_exists($lockPath))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('uses repository-local shared storage for a normal Git checkout', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-repository-'.bin2hex(random_bytes(8));
    $projectRoot = $temporaryDirectory.'/project';
    mkdir($projectRoot.'/.git', 0777, true);

    try {
        expect(PestTiaRuntime::storageDirectory($projectRoot, true))
            ->toBe((string) realpath($projectRoot).'/.git/relaticle/pest-tia');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('resolves shared storage through linked-worktree metadata', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-worktree-'.bin2hex(random_bytes(8));
    $projectRoot = $temporaryDirectory.'/project';
    $worktreeGitDirectory = $temporaryDirectory.'/common/worktrees/example';
    mkdir($projectRoot, 0777, true);
    mkdir($worktreeGitDirectory, 0777, true);
    file_put_contents($projectRoot.'/.git', "gitdir: ../common/worktrees/example\n");
    file_put_contents($worktreeGitDirectory.'/commondir', "../..\n");

    try {
        expect(PestTiaRuntime::storageDirectory($projectRoot, true))
            ->toBe((string) realpath($temporaryDirectory.'/common').'/relaticle/pest-tia');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('uses worktree-local storage for a targeted run', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-targeted-'.bin2hex(random_bytes(8));
    $projectRoot = $temporaryDirectory.'/project';
    mkdir($projectRoot, 0777, true);
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $script = <<<'PHP'
require $argv[1];
Tests\Helpers\PestTiaRuntime::configure($argv[2], ['tests/Arch']);
fwrite(STDOUT, Pest\Plugins\Tia\Storage::tempDir($argv[2]));
PHP;
    $process = new Process(
        [PHP_BINARY, '-r', $script, $autoloadPath, $projectRoot],
        env: [
            'RELATICLE_PEST_TIA_STORAGE' => false,
            'RELATICLE_PEST_TIA_SHARED' => false,
            'RELATICLE_PEST_TIA_LOCK_TOKEN' => false,
            'PARATEST' => false,
        ],
    );

    try {
        $process->run();

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getOutput())->toBe((string) realpath($projectRoot).'/storage/framework/testing/pest-tia')
            ->and(file_exists($temporaryDirectory.'/relaticle-pest-tia.lock'))->toBeFalse();
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('fails a shared recording without a coverage driver', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-driver-'.bin2hex(random_bytes(8));
    $projectRoot = $temporaryDirectory.'/project';
    mkdir($projectRoot.'/.git', 0777, true);
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $script = <<<'PHP'
require $argv[1];
Tests\Helpers\PestTiaRuntime::configure($argv[2], []);
PHP;
    $process = new Process(
        [PHP_BINARY, '-n', '-r', $script, $autoloadPath, $projectRoot],
        env: [
            'RELATICLE_PEST_TIA_STORAGE' => false,
            'RELATICLE_PEST_TIA_SHARED' => false,
            'RELATICLE_PEST_TIA_LOCK_TOKEN' => false,
            'PARATEST' => false,
        ],
    );

    try {
        $process->run();
        $failureOutput = $process->getErrorOutput().$process->getOutput();

        expect($process->isSuccessful())->toBeFalse()
            ->and($failureOutput)->toContain('PCOV')
            ->and($failureOutput)->toContain('XDEBUG_MODE=coverage');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});

it('does not require a coverage driver for a TIA-disabled run', function (): void {
    $temporaryDirectory = sys_get_temp_dir().'/relaticle-tia-no-driver-'.bin2hex(random_bytes(8));
    $projectRoot = $temporaryDirectory.'/project';
    mkdir($projectRoot, 0777, true);
    $autoloadPath = dirname(__DIR__, 2).'/vendor/autoload.php';
    $script = <<<'PHP'
require $argv[1];
Tests\Helpers\PestTiaRuntime::configure($argv[2], ['--no-tia']);
fwrite(STDOUT, "configured\n");
PHP;
    $process = new Process(
        [PHP_BINARY, '-n', '-r', $script, $autoloadPath, $projectRoot],
        env: [
            'RELATICLE_PEST_TIA_STORAGE' => false,
            'RELATICLE_PEST_TIA_SHARED' => false,
            'RELATICLE_PEST_TIA_LOCK_TOKEN' => false,
            'PARATEST' => false,
        ],
    );

    try {
        $process->run();

        expect($process->isSuccessful())->toBeTrue()
            ->and($process->getOutput())->toContain('configured');
    } finally {
        (new Filesystem)->deleteDirectory($temporaryDirectory);
    }
});
