<?php

declare(strict_types=1);

use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Date;
use Relaticle\SystemAdmin\Filament\Widgets\RecordDistributionChartWidget;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(RecordDistributionChartWidget::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['timezone' => 'Asia/Yerevan']), 'sysadmin');
    Filament::setCurrentPanel('sysadmin');
});

it('renders the widget', function (): void {
    livewire(RecordDistributionChartWidget::class)
        ->assertSuccessful();
});

it('opens its window at midnight on the administrator calendar', function (): void {
    $this->travelTo(Date::parse('2026-08-27 10:31:00', 'UTC'));

    $owner = User::factory()->withTeam()->create();
    $team = $owner->currentTeam;

    $company = fn (string $utc): Company => Company::withoutEvents(fn (): Company => Company::factory()
        ->for($team)
        ->create([
            'creator_id' => $owner->id,
            'creation_source' => CreationSource::WEB,
            'created_at' => Date::parse($utc, 'UTC'),
        ]));

    $company('2026-07-28 15:00:00');  // Jul 28 19:00 in Yerevan, the day before the window opens
    $company('2026-07-28 21:00:00');  // Jul 29 01:00 in Yerevan, the first day inside it

    $data = invade(livewire(RecordDistributionChartWidget::class)->assertOk()->instance())->getData();
    $counts = array_combine($data['labels'], $data['datasets'][0]['data']);

    expect($counts['Companies'])->toBe(1);
});
