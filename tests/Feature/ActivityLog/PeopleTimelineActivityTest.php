<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\ActivityLog\Filament\Livewire\TimelineLivewire;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);
});

it('loads causer eagerly when resolving activity_log entries so strict lazy-loading does not throw', function (): void {
    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);
    $person->update(['name' => 'Bravo']);

    $entries = TimelineBuilder::make($person)
        ->fromActivityLog()
        ->get();

    expect($entries->where('type', 'activity_log')->count())->toBeGreaterThanOrEqual(1);
});

it('renders activity_log entries in the TimelineLivewire UI when fromActivityLog() is chained', function (): void {
    $person = People::factory()->create([
        'name' => 'Alpha',
        'team_id' => $this->team->getKey(),
    ]);
    $person->update(['name' => 'Bravo']);

    livewire(TimelineLivewire::class, [
        'subjectClass' => $person::class,
        'subjectKey' => $person->getKey(),
        'perPage' => 20,
    ])->assertSeeHtml('data-type="activity_log"');
});
