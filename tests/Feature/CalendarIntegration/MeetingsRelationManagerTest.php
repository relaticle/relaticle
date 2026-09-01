<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\MeetingsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;

mutates(MeetingsRelationManager::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(
        fn () => ConnectedAccount::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
        ])
    );
});

it('shows meetings linked to this person only', function (): void {
    $person = People::factory()->for($this->team)->create();

    $linked = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $linked->people()->attach($person, ['link_source' => 'manual']);

    $other = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);

    livewire(MeetingsRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertCanSeeTableRecords([$linked])
        ->assertCanNotSeeTableRecords([$other]);
});

it('can render the meetings relation manager', function (): void {
    $person = People::factory()->for($this->team)->create();

    livewire(MeetingsRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertOk();
});
