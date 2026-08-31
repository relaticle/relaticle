<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\TeamScope;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\EditTeam;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ListTeams;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ViewTeam;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\ActivityRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\CompaniesRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager;
use Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(BillingStatus::class, TeamResource::class, MembersRelationManager::class, CompaniesRelationManager::class, ActivityRelationManager::class, PivotSafeTableQuery::class);

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

/**
 * @param  array<string, mixed>  $attributes
 */
function logTeamActivity(Team $team, ?User $causer = null, array $attributes = []): Activity
{
    return Activity::withoutGlobalScope(TeamScope::class)->create([
        'log_name' => 'crm',
        'description' => 'created',
        'event' => 'created',
        'subject_type' => 'company',
        'subject_id' => Company::withoutEvents(fn (): Company => Company::factory()->for($team)->create())->getKey(),
        'causer_type' => $causer instanceof User ? 'user' : null,
        'causer_id' => $causer?->getKey(),
        'team_id' => $team->getKey(),
        'properties' => [],
        ...$attributes,
    ]);
}

it('shows only the viewed workspace activity, which the tenant scope would otherwise hide entirely', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->ownedTeams()->firstOrFail();
    $other = User::factory()->withPersonalTeam()->create()->ownedTeams()->firstOrFail();

    $mine = logTeamActivity($team, $owner);
    $theirs = logTeamActivity($other);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSeeHtml("activity/{$mine->getKey()}");
});

it('reads a custom-field edit as the update it is, and names the field that moved', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->ownedTeams()->firstOrFail();

    logTeamActivity($team, null, [
        'event' => 'custom_field_changes',
        'description' => 'custom_field_changes',
        'properties' => ['custom_field_changes' => [['label' => 'Deal Stage', 'old' => 'New', 'new' => 'Won']]],
    ]);
    logTeamActivity($team, null, [
        'event' => 'updated',
        'description' => 'updated',
        'attribute_changes' => ['attributes' => ['name' => 'Acme Global'], 'old' => ['name' => 'Acme']],
    ]);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ])
        ->assertSuccessful()
        ->assertDontSee('custom_field_changes')
        ->assertSee('Deal Stage: New → Won')
        ->assertSee('Name: Acme → Acme Global');
});

it('counts a workspace activity on the tab badge', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->ownedTeams()->firstOrFail();

    expect(ActivityRelationManager::getBadge($team, ViewTeam::class))->toBeNull();

    logTeamActivity($team);

    expect(ActivityRelationManager::getBadge($team, ViewTeam::class))->toBe('1');
});

it('filters workspace activity by event', function (): void {
    $team = User::factory()->withPersonalTeam()->create()->ownedTeams()->firstOrFail();

    $created = logTeamActivity($team);
    $deleted = logTeamActivity($team, null, ['event' => 'deleted', 'description' => 'deleted']);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ])
        ->filterTable('event', 'deleted')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$created]);
});

it('filters workspace activity by the member who caused it', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $team = $owner->ownedTeams()->firstOrFail();

    $member = User::factory()->withPersonalTeam()->create();
    $team->users()->attach($member, ['role' => 'admin']);

    $byOwner = logTeamActivity($team, $owner);
    $byMember = logTeamActivity($team, $member);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $team,
        'pageClass' => ViewTeam::class,
    ])
        ->filterTable('causer', $member->getKey())
        ->assertCanSeeTableRecords([$byMember])
        ->assertCanNotSeeTableRecords([$byOwner]);
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

function billingStatusTeam(): Team
{
    return User::factory()->withPersonalTeam()->create()->ownedTeams()->firstOrFail();
}

it('labels a workspace by why it has its plan, not by the plan alone', function (callable $arrange, BillingStatus $expected): void {
    $team = billingStatusTeam();
    $arrange($team);

    expect($team->fresh()?->billingStatus())->toBe($expected);

    livewire(ListTeams::class)
        ->assertCanSeeTableRecords([$team])
        ->assertSee($expected->getLabel())
        ->assertSeeHtml($expected->getDescription());
})->with([
    'a trial reads Trial, never Pro' => [
        fn (Team $team) => $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save(),
        BillingStatus::Trialing,
    ],
    'a paid subscription reads Pro' => [
        function (Team $team): void {
            $team->forceFill(['plan' => Plan::Pro])->save();
            $team->subscriptions()->create([
                'type' => 'default',
                'stripe_id' => 'sub_active',
                'stripe_status' => 'active',
                'stripe_price' => 'price_pro_monthly_test',
                'quantity' => 1,
            ]);
        },
        BillingStatus::Subscribed,
    ],
    'a lapsed subscription reads Past due, not Pro' => [
        function (Team $team): void {
            $team->forceFill(['plan' => Plan::Pro])->save();
            $team->subscriptions()->create([
                'type' => 'default',
                'stripe_id' => 'sub_due',
                'stripe_status' => 'past_due',
                'stripe_price' => 'price_pro_monthly_test',
                'quantity' => 1,
            ]);
        },
        BillingStatus::PastDue,
    ],
    'a hand-assigned plan reads Granted, not Pro' => [
        fn (Team $team) => $team->forceFill(['plan' => Plan::Pro])->save(),
        BillingStatus::Granted,
    ],
    'an enterprise workspace reads Enterprise' => [
        fn (Team $team) => $team->forceFill(['plan' => Plan::Enterprise])->save(),
        BillingStatus::Enterprise,
    ],
    'a pre-billing workspace reads Free (legacy)' => [
        fn (Team $team) => $team->forceFill(['hosted_free_grandfathered_at' => now()])->save(),
        BillingStatus::Grandfathered,
    ],
]);

it('prefers a live subscription over a trial that has not run out yet', function (): void {
    $team = billingStatusTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    $team->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_converted',
        'stripe_status' => 'active',
        'stripe_price' => 'price_pro_monthly_test',
        'quantity' => 1,
    ]);

    expect($team->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);
});

it('reads a workspace with nothing bought and no trial as Free', function (): void {
    expect(billingStatusTeam()->billingStatus())->toBe(BillingStatus::Free);
});
