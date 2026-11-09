<?php

declare(strict_types=1);

use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Str;

mutates(Notification::class);

it('prunes read and archived notifications after 90 days and any notification after 365', function (): void {
    $user = User::factory()->create();

    $notify = fn (array $attributes): Notification => $user->notifications()->create([
        'id' => Str::uuid()->toString(),
        'type' => 'test',
        'data' => [],
        ...$attributes,
    ]);

    $readStale = $notify([
        'read_at' => now()->subDays(91),
        'created_at' => now()->subDays(91),
        'updated_at' => now()->subDays(91),
    ]);
    $archivedStale = $notify([
        'archived_at' => now()->subDays(91),
        'created_at' => now()->subDays(91),
        'updated_at' => now()->subDays(91),
    ]);
    $unreadStale = $notify([
        'created_at' => now()->subDays(91),
        'updated_at' => now()->subDays(91),
    ]);
    $unreadAncient = $notify([
        'created_at' => now()->subDays(366),
    ]);
    $readRecent = $notify([
        'read_at' => now()->subDays(10),
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);

    $this->artisan('model:prune', ['--model' => [Notification::class]])->assertSuccessful();

    expect(Notification::query()->pluck('id')->all())
        ->toEqualCanonicalizing([$unreadStale->id, $readRecent->id])
        ->not->toContain($readStale->id, $archivedStale->id, $unreadAncient->id);
});

it('returns the app notification model from the user relation', function (): void {
    $user = User::factory()->create();

    expect($user->notifications()->getRelated())->toBeInstanceOf(Notification::class);
});
