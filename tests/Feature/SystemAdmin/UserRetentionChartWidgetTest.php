<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Widgets\UserRetentionChartWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(UserRetentionChartWidget::class);

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
    $this->travelTo(now()->next('Wednesday')->midDay());

    $newUser = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(2),
    ]);

    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $newUser->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDay(),
        ]));

    $returningUser = User::factory()->withTeam()->create([
        'created_at' => now()->subDays(30),
    ]);

    Company::withoutEvents(fn () => Company::factory()
        ->for($this->team)
        ->create([
            'creator_id' => $returningUser->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => now()->subDay(),
        ]));

    $component = livewire(UserRetentionChartWidget::class)->assertOk();

    $chartData = invade($component->instance())->getData();

    $datasetsByLabel = collect($chartData['datasets'])->keyBy('label');

    $newActiveData = $datasetsByLabel['New Active']['data'] ?? [];
    $returningData = $datasetsByLabel['Returning']['data'] ?? [];

    expect(array_sum($newActiveData))->toBe(1)
        ->and(array_sum($returningData))->toBe(1);
});
