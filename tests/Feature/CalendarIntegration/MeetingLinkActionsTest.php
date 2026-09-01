<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\MeetingsRelationManager;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
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

it('links a meeting to a company via the modal action', function (): void {
    $person = People::factory()->for($this->team)->create();
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $meeting->people()->attach($person, ['link_source' => 'manual']);
    $company = Company::factory()->for($this->team)->create();

    livewire(MeetingsRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewPeople::class])
        ->callAction(TestAction::make('linkToRecord')->table($meeting), [
            'target_type' => 'Company',
            'target_id' => $company->getKey(),
        ])
        ->assertNotified();

    expect($meeting->fresh()?->companies()->count())->toBe(1);
});

it('unlinks a meeting from the owner record via the modal action', function (): void {
    $person = People::factory()->for($this->team)->create();
    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
    ]);
    $meeting->people()->attach($person, ['link_source' => 'manual']);

    livewire(MeetingsRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewPeople::class])
        ->callAction(TestAction::make('unlinkFromRecord')->table($meeting))
        ->assertNotified();

    expect($meeting->fresh()?->people()->count())->toBe(0);
});
