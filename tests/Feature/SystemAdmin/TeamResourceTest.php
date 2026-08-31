<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\Plan;
use App\Models\Company;
use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\EditTeam;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ListTeams;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\Pages\ViewTeam;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\CompaniesRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\TeamResource\RelationManagers\MembersRelationManager;
use Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(BillingStatus::class, TeamResource::class, MembersRelationManager::class, CompaniesRelationManager::class, PivotSafeTableQuery::class);

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

function billingStatusTeam(): Team
{
    return User::factory()->withPersonalTeam()->create()->ownedTeams()->firstOrFail();
}

/**
 * @return array<string, array{0: callable(Team): void, 1: BillingStatus}>
 */
function billingStatusArrangements(): array
{
    return [
        'a trial reads Trial, never Pro' => [
            fn (Team $team) => $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save(),
            BillingStatus::Trialing,
        ],
        'a paid subscription reads Pro' => [
            function (Team $team): void {
                $team->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->active()->create(['team_id' => $team->getKey()]);
            },
            BillingStatus::Subscribed,
        ],
        'a lapsed subscription reads Past due, not Pro' => [
            function (Team $team): void {
                $team->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->pastDue()->create(['team_id' => $team->getKey()]);
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
        'a workspace with nothing bought reads Free' => [
            fn (Team $team): null => null,
            BillingStatus::Free,
        ],
    ];
}

it('labels a workspace by why it has its plan, not by the plan alone', function (callable $arrange, BillingStatus $expected): void {
    $team = billingStatusTeam();
    $arrange($team);

    expect($team->fresh()?->billingStatus())->toBe($expected);

    livewire(ListTeams::class)
        ->assertCanSeeTableRecords([$team])
        ->assertSee($expected->getLabel())
        ->assertSeeHtml($expected->getDescription());
})->with(billingStatusArrangements());

it('filters workspaces by the badge they show, and by no other', function (): void {
    $teams = [];

    foreach (billingStatusArrangements() as [$arrange, $status]) {
        $team = billingStatusTeam();
        $arrange($team);
        $teams[$status->value] = $team->fresh();
    }

    expect(array_keys($teams))
        ->toEqualCanonicalizing(array_column(BillingStatus::cases(), 'value'));

    expect(collect($teams)->map(fn (Team $team): string => $team->billingStatus()->value)->all())
        ->toBe(array_combine(array_keys($teams), array_keys($teams)));

    foreach ($teams as $value => $team) {
        livewire(ListTeams::class)
            ->filterTable('billing_status', [$value])
            ->assertCanSeeTableRecords([$team])
            ->assertCanNotSeeTableRecords(collect($teams)->except($value)->values()->all());
    }
});

it('filters on the subscription a workspace bills on now, not a superseded one', function (): void {
    $team = billingStatusTeam();
    $team->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->pastDue()->create([
        'team_id' => $team->getKey(),
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    Subscription::factory()->active()->create(['team_id' => $team->getKey()]);

    expect($team->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListTeams::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$team]);

    livewire(ListTeams::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$team]);
});

it('filters a workspace still inside its grace period as Pro, the way the badge reads it', function (): void {
    $team = billingStatusTeam();
    $team->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->unpaid()->create([
        'team_id' => $team->getKey(),
        'ends_at' => now()->addDays(5),
    ]);

    expect($team->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListTeams::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$team]);

    livewire(ListTeams::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$team]);
});

it('filters on the default subscription, ignoring a newer one of another type', function (): void {
    $team = billingStatusTeam();
    $team->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->active()->create([
        'team_id' => $team->getKey(),
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    Subscription::factory()->pastDue()->create([
        'team_id' => $team->getKey(),
        'type' => 'addon',
    ]);

    expect($team->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListTeams::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$team]);

    livewire(ListTeams::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$team]);
});

it('prefers a live subscription over a trial that has not run out yet', function (): void {
    $team = billingStatusTeam();
    $team->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    Subscription::factory()->active()->create(['team_id' => $team->getKey()]);

    expect($team->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);
});
