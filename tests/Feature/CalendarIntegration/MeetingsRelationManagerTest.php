<?php

declare(strict_types=1);

use App\Enums\CustomFields\PeopleField;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\MeetingsRelationManager;
use App\Models\CustomField;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\RelationManagers\BaseMeetingsRelationManager;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\EmailVisibilityService;

mutates(MeetingsRelationManager::class, BaseMeetingsRelationManager::class, EmailVisibilityService::class);

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

it('hides meetings on a protected person', function (): void {
    $person = People::factory()->for($this->team)->create();

    $emailsField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->team->id)
        ->where('entity_type', 'people')
        ->where('code', PeopleField::EMAILS->value)
        ->firstOrFail();

    $person->saveCustomFieldValue($emailsField, [$this->user->email], $this->team);

    $meeting = Meeting::factory()->create([
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->id,
        'title' => 'Hidden on the protected record',
    ]);
    $meeting->people()->attach($person, ['link_source' => 'manual']);

    livewire(MeetingsRelationManager::class, [
        'ownerRecord' => $person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertSee(__('filament/pages/record-emails.protected.heading'))
        ->assertCanNotSeeTableRecords([$meeting]);
});
