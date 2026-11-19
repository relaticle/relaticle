<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $this->scanDir = sys_get_temp_dir().'/audit-scan-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->scanDir.'/state');
    File::ensureDirectoryExists($this->scanDir.'/site');
});

afterEach(function (): void {
    File::deleteDirectory($this->scanDir);
});

/**
 * @param  array<int, array{exe: string, path: string, ses: string, auid?: string, proctitle?: string}>  $reads
 * @param  array<int, string>  $cronSessions
 * @return array<int, array{at: int, exe: string, auid: string, path: string}>
 */
function runAuditScan(string $dir, array $reads, array $cronSessions): array
{
    $stamp = gmdate('m/d/Y H:i:s', time() - 60);
    $records = collect($reads)->map(fn (array $read, int $serial): string => implode("\n", [
        '----',
        "type=PROCTITLE msg=audit({$stamp}.100:{$serial}) : proctitle=".($read['proctitle'] ?? basename($read['exe'])),
        "type=PATH msg=audit({$stamp}.100:{$serial}) : item=0 name={$read['path']} nametype=NORMAL",
        "type=CWD msg=audit({$stamp}.100:{$serial}) : cwd=/home/forge",
        "type=SYSCALL msg=audit({$stamp}.100:{$serial}) : arch=x86_64 syscall=openat success=yes auid=".($read['auid'] ?? 'forge')." ses={$read['ses']} exe={$read['exe']} key=relaticle-secrets",
    ]))->implode("\n");

    File::put("{$dir}/records", $records."\n");
    File::put("{$dir}/cron-sessions", implode("\n", $cronSessions)."\n");
    File::put("{$dir}/ausearch", <<<'SH'
        #!/bin/bash
        dir="$(dirname "$0")"
        session=""
        while [[ $# -gt 0 ]]; do
            [[ "$1" == "--session" ]] && session="$2"
            shift
        done
        if [[ -n "$session" ]]; then
            grep -qx "$session" "$dir/cron-sessions" && echo "type=USER_START msg=audit(01/01/2026 00:00:00.000:1) : pid=1 uid=root auid=forge ses=$session exe=/usr/sbin/cron"
            exit 0
        fi
        cat "$dir/records"
        SH);
    chmod("{$dir}/ausearch", 0755);

    Process::env([
        'AUSEARCH' => "{$dir}/ausearch",
        'STATE_DIR' => "{$dir}/state",
        'SITE_DIR' => "{$dir}/site",
        'TZ' => 'UTC',
    ])->run(['bash', base_path('packages/Cloud/ops/auditd/relaticle-audit-scan.sh')])->throw();

    return json_decode(File::get("{$dir}/state/status.json"), true)['unexpected'];
}

it('expects the nightly backup job to list and upload the backups directory', function (): void {
    $unexpected = runAuditScan($this->scanDir, [
        ['exe' => '/usr/bin/find', 'path' => '/home/forge/backups', 'ses' => '41'],
        ['exe' => '/usr/bin/sftp', 'path' => '/home/forge/backups/relaticle_pg_20261120_030001.dump', 'ses' => '41'],
    ], cronSessions: ['41']);

    expect($unexpected)->toBe([]);
});

it('reports an sftp read of a backup from a login session', function (): void {
    $unexpected = runAuditScan($this->scanDir, [
        ['exe' => '/usr/bin/sftp', 'path' => '/home/forge/backups/relaticle_pg_20261120_030001.dump', 'ses' => '77'],
    ], cronSessions: ['41']);

    expect($unexpected)->toHaveCount(1)
        ->and($unexpected[0]['exe'])->toBe('/usr/bin/sftp');
});

it('reports a cron sftp read outside the backups directory', function (): void {
    $unexpected = runAuditScan($this->scanDir, [
        ['exe' => '/usr/bin/sftp', 'path' => '/home/forge/relaticle.com/.env', 'ses' => '41'],
        ['exe' => '/usr/bin/sftp', 'path' => '/home/forge/backups-copy/relaticle.dump', 'ses' => '41'],
    ], cronSessions: ['41']);

    expect(array_column($unexpected, 'path'))->toBe([
        '/home/forge/relaticle.com/.env',
        '/home/forge/backups-copy/relaticle.dump',
    ]);
});

it('still reports other programs reading a backup from a cron session', function (): void {
    $unexpected = runAuditScan($this->scanDir, [
        ['exe' => '/usr/bin/cat', 'path' => '/home/forge/backups/relaticle_pg_20261120_030001.dump', 'ses' => '41'],
    ], cronSessions: ['41']);

    expect($unexpected)->toHaveCount(1);
});
