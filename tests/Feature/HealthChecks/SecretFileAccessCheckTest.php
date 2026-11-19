<?php

declare(strict_types=1);

use App\Health\SecretFileAccessCheck;
use Illuminate\Support\Facades\File;
use Spatie\Health\Enums\Status;

mutates(SecretFileAccessCheck::class);

beforeEach(function (): void {
    $this->travelTo('2026-11-19 10:00:00');
    $this->statusPath = tempnam(sys_get_temp_dir(), 'secret-access');
    config(['health.secret_access_status_path' => $this->statusPath]);
});

afterEach(function (): void {
    File::delete($this->statusPath);
});

function writeSecretAccessStatus(string $path, int $scannedAt, array $unexpected = []): void
{
    file_put_contents($path, json_encode(['scanned_at' => $scannedAt, 'unexpected' => $unexpected]));
}

function unexpectedSecretRead(int $at, string $exe = '/usr/bin/cat'): array
{
    return ['at' => $at, 'exe' => $exe, 'auid' => 'forge', 'path' => '/home/forge/relaticle.com/.env'];
}

it('passes when the scan is fresh and found nothing', function (): void {
    writeSecretAccessStatus($this->statusPath, now()->subMinutes(4)->timestamp);

    expect(SecretFileAccessCheck::new()->run()->status)->toBe(Status::ok());
});

it('fails for a day after an unexpected read', function (): void {
    $readAt = now()->timestamp;

    $this->travel(23)->hours();
    writeSecretAccessStatus($this->statusPath, now()->timestamp, [unexpectedSecretRead($readAt)]);
    $failing = SecretFileAccessCheck::new()->run();

    $this->travel(2)->hours();
    writeSecretAccessStatus($this->statusPath, now()->timestamp, [unexpectedSecretRead($readAt)]);

    expect($failing->status)->toBe(Status::failed())
        ->and($failing->notificationMessage)->toBe('1 unexpected read of a production secret file in the last 24 hours. Newest: /usr/bin/cat read /home/forge/relaticle.com/.env.')
        ->and(SecretFileAccessCheck::new()->run()->status)->toBe(Status::ok());
});

it('names the newest reader when several reads are unexpected', function (): void {
    writeSecretAccessStatus($this->statusPath, now()->timestamp, [
        unexpectedSecretRead(now()->subHours(3)->timestamp, '/usr/bin/vim'),
        unexpectedSecretRead(now()->subHour()->timestamp, '/usr/bin/less'),
    ]);

    $result = SecretFileAccessCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toContain('2 unexpected reads')
        ->and($result->notificationMessage)->toContain('/usr/bin/less');
});

it('fails when the scan file is older than 30 minutes', function (): void {
    writeSecretAccessStatus($this->statusPath, now()->subMinutes(31)->timestamp);

    $result = SecretFileAccessCheck::new()->run();

    expect($result->status)->toBe(Status::failed())
        ->and($result->notificationMessage)->toContain('The secret file scan last ran');
});

it('fails when the scan file is missing', function (): void {
    File::delete($this->statusPath);

    expect(SecretFileAccessCheck::new()->run()->status)->toBe(Status::failed());
});

it('fails when the scan file is not valid json', function (): void {
    file_put_contents($this->statusPath, 'not json');

    expect(SecretFileAccessCheck::new()->run()->status)->toBe(Status::failed());
});

it('treats a scan exactly 30 minutes old as fresh and one second older as stale', function (): void {
    writeSecretAccessStatus($this->statusPath, now()->subMinutes(30)->timestamp);
    $atBoundary = SecretFileAccessCheck::new()->run();

    writeSecretAccessStatus($this->statusPath, now()->subMinutes(30)->subSecond()->timestamp);

    expect($atBoundary->status)->toBe(Status::ok())
        ->and(SecretFileAccessCheck::new()->run()->status)->toBe(Status::failed());
});

it('stops failing exactly 24 hours after an unexpected read', function (): void {
    writeSecretAccessStatus($this->statusPath, now()->timestamp, [unexpectedSecretRead(now()->subHours(24)->addSecond()->timestamp)]);
    $justInside = SecretFileAccessCheck::new()->run();

    writeSecretAccessStatus($this->statusPath, now()->timestamp, [unexpectedSecretRead(now()->subHours(24)->timestamp)]);

    expect($justInside->status)->toBe(Status::failed())
        ->and(SecretFileAccessCheck::new()->run()->status)->toBe(Status::ok());
});
