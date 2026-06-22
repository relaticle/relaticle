<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\User;
use App\Support\ActivityLog\CleanActivityLogAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('only returns activities for the current tenant', function (): void {
    Company::factory()->for($this->team)->create();

    $otherUser = User::factory()->withTeam()->create();
    $otherTeam = $otherUser->currentTeam;

    Filament::setTenant($otherTeam);
    $this->actingAs($otherUser);

    Company::factory()->for($otherTeam)->create();

    Filament::setTenant($this->team);
    $this->actingAs($this->user);

    $activities = Activity::all();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->team_id)->toBe($this->team->getKey());
});

it('returns no rows when no tenant is set', function (): void {
    Company::factory()->for($this->team)->create();

    Filament::setTenant(null);

    expect(Activity::query()->count())->toBe(0);
});

it('lets the custom CleanActivityLogAction purge globally via withoutGlobalScopes', function (): void {
    Company::factory()->for($this->team)->create();

    $activity = Activity::withoutGlobalScopes()->first();
    $activity->created_at = now()->subDays(400);
    $activity->save();

    (new CleanActivityLogAction)->execute(maxAgeInDays: 365);

    expect(Activity::withoutGlobalScopes()->count())->toBe(0);
});

it('auto-populates team_id from the subject on create', function (): void {
    $company = Company::factory()->for($this->team)->create();

    $activity = Activity::withoutGlobalScopes()
        ->where('subject_type', $company->getMorphClass())
        ->where('subject_id', $company->getKey())
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->team_id)->toBe($this->team->getKey());
});
