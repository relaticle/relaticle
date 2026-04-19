<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;

mutates(Activity::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('logs a created activity with team_id auto-populated', function (): void {
    $company = Company::factory()->create([
        'name' => 'Acme Corp',
        'team_id' => $this->team->getKey(),
    ]);

    $activity = Activity::query()
        ->where('subject_type', $company->getMorphClass())
        ->where('subject_id', $company->getKey())
        ->where('event', 'created')
        ->firstOrFail();

    expect($activity->team_id)->toBe($this->team->getKey())
        ->and($activity->causer_id)->toBe($this->user->getKey());
});

it('logs an updated activity with attribute_changes', function (): void {
    $company = Company::factory()->create([
        'name' => 'Initial',
        'team_id' => $this->team->getKey(),
    ]);
    $company->update(['name' => 'Renamed']);

    $activity = Activity::query()
        ->where('subject_type', $company->getMorphClass())
        ->where('subject_id', $company->getKey())
        ->where('event', 'updated')
        ->firstOrFail();

    $changes = $activity->attribute_changes?->toArray() ?? [];

    expect($changes['attributes']['name'] ?? null)->toBe('Renamed')
        ->and($changes['old']['name'] ?? null)->toBe('Initial');
});

it('logs a deleted activity', function (): void {
    $company = Company::factory()->create([
        'team_id' => $this->team->getKey(),
    ]);
    $company->delete();

    $count = Activity::query()
        ->where('subject_type', $company->getMorphClass())
        ->where('subject_id', $company->getKey())
        ->where('event', 'deleted')
        ->count();

    expect($count)->toBe(1);
});

it('logs a restored activity', function (): void {
    $company = Company::factory()->create([
        'team_id' => $this->team->getKey(),
    ]);
    $company->delete();
    $company->restore();

    $count = Activity::query()
        ->where('subject_type', $company->getMorphClass())
        ->where('subject_id', $company->getKey())
        ->where('event', 'restored')
        ->count();

    expect($count)->toBe(1);
});

it('scopes activities to the current team', function (): void {
    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    Company::factory()->create([
        'team_id' => $this->team->getKey(),
    ]);

    $this->actingAs($otherUser);
    Filament::setTenant($otherTeam);

    expect(Activity::query()->count())->toBe(0);
});
