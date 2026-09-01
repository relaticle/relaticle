<?php

declare(strict_types=1);

use App\Console\Commands\TapeCommand;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Artisan;

mutates(TapeCommand::class);
mutates(AppServiceProvider::class);

it('registers tape as an artisan command', function (): void {
    expect(Artisan::all())->toHaveKey('tape');
});

it('includes horizon, reverb, logs, the scheduler, and the default processes instead of queue:listen', function (): void {
    expect(Artisan::call('dev:list', ['--json' => true]))->toBe(0);

    /** @var list<array{name: string, command: string}> $processes */
    $processes = json_decode(trim(Artisan::output()), true, flags: JSON_THROW_ON_ERROR);

    $byName = collect($processes)->keyBy('name');

    expect($byName->keys()->all())
        ->toContain('horizon', 'reverb', 'scheduler', 'server', 'vite')
        ->not->toContain('queue');

    expect($byName['horizon']['command'])->toContain('horizon')
        ->and($byName['reverb']['command'])->toContain('reverb:start')
        ->and($byName['scheduler']['command'])->toContain('schedule:work')
        ->and($byName['server']['command'])->toContain('serve')
        ->and($byName['vite']['command'])->toContain('dev');

    if (function_exists('pcntl_fork')) {
        expect($byName->keys()->all())->toContain('logs')
            ->and($byName['logs']['command'])->toContain('pail');
    }
});
