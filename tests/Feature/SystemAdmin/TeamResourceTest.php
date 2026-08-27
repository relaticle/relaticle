<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\EditTeam;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ListTeams;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ViewTeam;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\CompaniesRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager;
use Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(TeamResource::class, MembersRelationManager::class, CompaniesRelationManager::class, PivotSafeTableQuery::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('links team members to the user view page using the user key, not the pivot key', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->ownedTeams()->first();

    $member = User::factory()->withPersonalTeam()->create();
    $team->users()->attach($member, ['role' => 'admin']);

    livewire(MembersRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ])
        ->assertSuccessful()
        ->assertSeeHtml("users/{$member->getKey()}");
});

it('links team companies to the company view page', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->ownedTeams()->first();

    $company = Company::factory()->for($team)->create(['creator_id' => $owner->getKey()]);

    livewire(CompaniesRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ])
        ->assertSuccessful()
        ->assertSeeHtml("companies/{$company->getKey()}")
        ->assertSeeHtml("users/{$owner->getKey()}");
});

it('deletes a team through the Jetstream deleter so members keep no dangling current team', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->ownedTeams()->firstOrFail();

    $member = User::factory()->withPersonalTeam()->create();
    $team->users()->attach($member, ['role' => 'admin']);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    livewire(EditTeam::class, ['record' => $team->getKey()])
        ->callAction('delete')
        ->assertHasNoActionErrors();

    expect(Team::query()->find($team->getKey()))->toBeNull()
        ->and($member->refresh()->current_team_id)->toBeNull();
});

it('deletes teams in bulk through the Jetstream deleter so members keep no dangling current team', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->ownedTeams()->firstOrFail();
    $second = User::factory()->withPersonalTeam()->create()->ownedTeams()->firstOrFail();

    $member = User::factory()->withPersonalTeam()->create();
    $team->users()->attach($member, ['role' => 'admin']);
    $member->forceFill(['current_team_id' => $team->getKey()])->save();

    livewire(ListTeams::class)
        ->selectTableRecords([$team->getKey(), $second->getKey()])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertHasNoActionErrors();

    expect(Team::query()->whereKey([$team->getKey(), $second->getKey()])->count())->toBe(0)
        ->and($member->refresh()->current_team_id)->toBeNull();
});
