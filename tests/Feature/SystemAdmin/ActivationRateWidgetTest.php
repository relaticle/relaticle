<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Note;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\ActivationRateWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

beforeEach(function () {
    $this->admin = SystemAdministrator::factory()->create();
    $this->actingAs($this->admin, 'sysadmin');
    Filament::setCurrentPanel('sysadmin');

    $this->teamOwner = User::factory()->withTeam()->create();
    $this->team = $this->teamOwner->currentTeam;
});

it('can render the activation rate widget', function () {
    livewire(ActivationRateWidget::class)
        ->assertOk();
});

it('counts activated users who created records manually', function () {
    // 3 users who sign up this period
    $users = User::factory(3)->withTeam()->create([
        'created_at' => now()->subDays(5),
    ]);

    // 1 user creates a company (activated)
    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $users[0]->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDays(4),
        ]));

    // 1 user creates a note (activated)
    Note::withoutEvents(fn () => Note::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $users[1]->id,
            'created_at' => now()->subDays(3),
        ]));

    // 1 user does nothing (not activated)
    // $users[2] has no records

    $widget = livewire(ActivationRateWidget::class);
    $instance = $widget->instance();
    $method = new ReflectionMethod($instance, 'getStats');
    $stats = $method->invoke($instance);

    // Should show at least 2 activated users in the stats
    expect($stats)->toHaveCount(3);
});

it('excludes system-created records from activation count', function () {
    $user = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(5),
    ]);

    // System-created record should NOT count as activation
    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $user->id,
            'creation_source' => CreationSource::SYSTEM,
            'created_at' => now()->subDays(4),
        ]));

    $widget = livewire(ActivationRateWidget::class);
    $instance = $widget->instance();
    $method = new ReflectionMethod($instance, 'getStats');
    $stats = $method->invoke($instance);

    expect($stats)->toHaveCount(3);
});
