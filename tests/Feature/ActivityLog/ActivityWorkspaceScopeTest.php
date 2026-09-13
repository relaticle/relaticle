<?php

declare(strict_types=1);

use App\Models\ActivityLog\Activity;
use App\Models\Company;
use App\Models\User;
use App\Support\ActivityLog\CleanActivityLogAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    $this->workspace = $this->user->currentWorkspace;
    Filament::setTenant($this->workspace);
});

it('only returns activities for the current tenant', function (): void {
    Company::factory()->for($this->workspace)->create();

    $otherUser = User::factory()->withWorkspace()->create();
    $otherWorkspace = $otherUser->currentWorkspace;

    Filament::setTenant($otherWorkspace);
    $this->actingAs($otherUser);

    Company::factory()->for($otherWorkspace)->create();

    Filament::setTenant($this->workspace);
    $this->actingAs($this->user);

    $activities = Activity::all();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->workspace_id)->toBe($this->workspace->getKey());
});

it('returns no rows when no tenant is set', function (): void {
    Company::factory()->for($this->workspace)->create();

    Filament::setTenant(null);

    expect(Activity::query()->count())->toBe(0);
});

it('lets the custom CleanActivityLogAction purge globally via withoutGlobalScopes', function (): void {
    Company::factory()->for($this->workspace)->create();

    $activity = Activity::withoutGlobalScopes()->first();
    $activity->created_at = now()->subDays(400);
    $activity->save();

    (new CleanActivityLogAction)->execute(maxAgeInDays: 365);

    expect(Activity::withoutGlobalScopes()->count())->toBe(0);
});

it('auto-populates workspace_id from the subject on create', function (): void {
    $company = Company::factory()->for($this->workspace)->create();

    $activity = Activity::withoutGlobalScopes()
        ->where('subject_type', $company->getMorphClass())
        ->where('subject_id', $company->getKey())
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->workspace_id)->toBe($this->workspace->getKey());
});
