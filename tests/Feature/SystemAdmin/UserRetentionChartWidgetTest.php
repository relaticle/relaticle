<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\UserRetentionChartWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

beforeEach(function () {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $this->teamOwner = User::factory()->withTeam()->create();
    $this->team = $this->teamOwner->currentTeam;
});

it('can render the user retention chart widget', function () {
    livewire(UserRetentionChartWidget::class)
        ->assertOk();
});

it('classifies new active vs returning users correctly', function () {
    // "New active" user: signed up this week AND created a record
    $newUser = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(2),
    ]);

    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $newUser->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(1),
        ]));

    // "Returning" user: signed up weeks ago, created a record this week
    $returningUser = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(30),
    ]);

    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $returningUser->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(1),
        ]));

    $widget = livewire(UserRetentionChartWidget::class);

    // Widget should render without errors
    $widget->assertOk();
});
