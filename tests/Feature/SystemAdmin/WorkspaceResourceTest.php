<?php

declare(strict_types=1);

use App\Enums\BillingStatus;
use App\Enums\CreationSource;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Enums\Plan;
use App\Features\Billing;
use App\Models\ActivityLog\Activity;
use App\Models\ActivityLog\Scopes\WorkspaceScope;
use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\HostedWorkspaceAccess;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\SystemAdmin\Actions\UpdateCustomerRecord;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Pages\EditCustomerRecord;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\CreateWorkspace;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\EditWorkspace;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ViewWorkspace;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers\ActivityRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers\CompaniesRelationManager;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\RelationManagers\MembersRelationManager;
use Relaticle\SystemAdmin\Filament\Support\Impersonate;
use Relaticle\SystemAdmin\Filament\Support\PivotSafeTableQuery;
use Relaticle\SystemAdmin\Metrics\WorkspaceJourney;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\OverviewData;

mutates(UpdateCustomerRecord::class, EditCustomerRecord::class, BillingStatus::class, WorkspaceResource::class, MembersRelationManager::class, CompaniesRelationManager::class, ActivityRelationManager::class, PivotSafeTableQuery::class, Impersonate::class, WorkspaceJourney::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('rejects administrator reassignment of workspace ownership', function (): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $workspace = Workspace::factory()->create();
    $ownerId = $workspace->user_id;
    $other = User::factory()->create();

    livewire(EditWorkspace::class, ['record' => $workspace->getKey()])
        ->set('data.user_id', $other->getKey())
        ->call('save')
        ->assertForbidden();

    expect($workspace->refresh()->user_id)->toBe($ownerId);
});

it('does not reassign workspace ownership through navigation actions', function (string $pageClass): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $workspace = Workspace::factory()->create();
    $ownerId = $workspace->user_id;
    $other = User::factory()->create();
    $action = TestAction::make('edit');

    if ($pageClass === ListWorkspaces::class) {
        $action->table($workspace);
    }

    livewire($pageClass, ['record' => $workspace->getKey()])
        ->callAction($action, data: ['user_id' => $other->getKey()])
        ->assertHasNoActionErrors();

    expect($workspace->refresh()->user_id)->toBe($ownerId);
})->with([ListWorkspaces::class, ViewWorkspace::class]);

it('rejects administrator changes to personal workspace status', function (): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $workspace = Workspace::factory()->create(['personal_workspace' => true]);

    livewire(EditWorkspace::class, ['record' => $workspace->getKey()])
        ->set('data.personal_workspace', false)
        ->call('save')
        ->assertForbidden();

    expect($workspace->refresh()->personal_workspace)->toBeTrue();
});

it('lets administrators edit ordinary workspace details', function (): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $workspace = Workspace::factory()->create();
    $ownerId = $workspace->user_id;

    livewire(EditWorkspace::class, ['record' => $workspace->getKey()])
        ->fillForm(['name' => 'Updated Workspace'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($workspace->refresh()->name)->toBe('Updated Workspace')
        ->and($workspace->user_id)->toBe($ownerId);
});

it('lets super administrators change workspace ownership and personal status', function (): void {
    $workspace = Workspace::factory()->create(['personal_workspace' => true]);
    $other = User::factory()->create();

    livewire(EditWorkspace::class, ['record' => $workspace->getKey()])
        ->fillForm(['user_id' => $other->getKey(), 'personal_workspace' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($workspace->refresh()->user_id)->toBe($other->getKey())
        ->and($workspace->personal_workspace)->toBeFalse();
});

it('lets administrators create workspaces', function (): void {
    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
    $owner = User::factory()->create();

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'New Workspace', 'slug' => 'new-workspace', 'user_id' => $owner->getKey(), 'personal_workspace' => false])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas('workspaces', ['slug' => 'new-workspace', 'user_id' => $owner->getKey()]);
});

it('links workspace members to the user view page using the user key, not the pivot key', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);

    livewire(MembersRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertSeeHtml("users/{$member->getKey()}");
});

it('links workspace companies to the company view page', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();

    $company = Company::factory()->for($workspace)->create(['creator_id' => $owner->getKey()]);

    livewire(CompaniesRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertSeeHtml("companies/{$company->getKey()}")
        ->assertSeeHtml("users/{$owner->getKey()}");
});

/**
 * @param  array<string, mixed>  $attributes
 */
function logWorkspaceActivity(Workspace $workspace, ?User $causer = null, array $attributes = []): Activity
{
    return Activity::withoutGlobalScope(WorkspaceScope::class)->create([
        'log_name' => 'crm',
        'description' => 'created',
        'event' => 'created',
        'subject_type' => 'company',
        'subject_id' => Company::withoutEvents(fn (): Company => Company::factory()->for($workspace)->create())->getKey(),
        'causer_type' => $causer instanceof User ? 'user' : null,
        'causer_id' => $causer?->getKey(),
        'workspace_id' => $workspace->getKey(),
        'properties' => [],
        ...$attributes,
    ]);
}

it('shows only the viewed workspace activity, which the tenant scope would otherwise hide entirely', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();
    $other = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    $mine = logWorkspaceActivity($workspace, $owner);
    $theirs = logWorkspaceActivity($other);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs])
        ->assertSeeHtml("activity/{$mine->getKey()}");
});

it('reads a custom-field edit as the update it is, and names the field that moved', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    logWorkspaceActivity($workspace, null, [
        'event' => 'custom_field_changes',
        'description' => 'custom_field_changes',
        'properties' => ['custom_field_changes' => [['label' => 'Deal Stage', 'old' => 'New', 'new' => 'Won']]],
    ]);
    logWorkspaceActivity($workspace, null, [
        'event' => 'updated',
        'description' => 'updated',
        'attribute_changes' => ['attributes' => ['name' => 'Acme Global'], 'old' => ['name' => 'Acme']],
    ]);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertDontSee('custom_field_changes')
        ->assertSee('Deal Stage: New → Won')
        ->assertSee('Name: Acme → Acme Global');
});

it('cuts a long change short in the activity table', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    logWorkspaceActivity($workspace, null, [
        'event' => 'custom_field_changes',
        'description' => 'custom_field_changes',
        'properties' => ['custom_field_changes' => [['label' => 'Body', 'old' => 'Draft', 'new' => str_repeat('lorem ', 500).'closing words']]],
    ]);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->assertSuccessful()
        ->assertSee('Body: Draft → lorem')
        ->assertDontSee('closing words');
});

it('counts a workspace activity on the tab badge', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    expect(ActivityRelationManager::getBadge($workspace, ViewWorkspace::class))->toBeNull();

    logWorkspaceActivity($workspace);

    expect(ActivityRelationManager::getBadge($workspace, ViewWorkspace::class))->toBe('1');
});

it('filters workspace activity by event', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    $created = logWorkspaceActivity($workspace);
    $deleted = logWorkspaceActivity($workspace, null, ['event' => 'deleted', 'description' => 'deleted']);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->filterTable('event', 'deleted')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$created]);
});

it('filters workspace activity by the member who caused it', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);

    $byOwner = logWorkspaceActivity($workspace, $owner);
    $byMember = logWorkspaceActivity($workspace, $member);

    livewire(ActivityRelationManager::class, [
        'ownerRecord' => $workspace,
        'pageClass' => ViewWorkspace::class,
    ])
        ->filterTable('causer', $member->getKey())
        ->assertCanSeeTableRecords([$byMember])
        ->assertCanNotSeeTableRecords([$byOwner]);
});

it('deletes a workspace through the Jetstream deleter so members keep no dangling current workspace', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);
    $member->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    livewire(EditWorkspace::class, ['record' => $workspace->getKey()])
        ->callAction('delete')
        ->assertHasNoActionErrors();

    expect(Workspace::query()->find($workspace->getKey()))->toBeNull()
        ->and($member->refresh()->current_workspace_id)->toBeNull();
});

it('deletes workspaces in bulk through the Jetstream deleter so members keep no dangling current workspace', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->firstOrFail();
    $second = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    $member = User::factory()->withPersonalWorkspace()->create();
    $workspace->users()->attach($member, ['role' => 'admin']);
    $member->forceFill(['current_workspace_id' => $workspace->getKey()])->save();

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$workspace->getKey(), $second->getKey()])
        ->callAction([['name' => 'delete', 'context' => ['table' => true, 'bulk' => true]]])
        ->assertHasNoActionErrors();

    expect(Workspace::query()->whereKey([$workspace->getKey(), $second->getKey()])->count())->toBe(0)
        ->and($member->refresh()->current_workspace_id)->toBeNull();
});

function billingStatusWorkspace(): Workspace
{
    return User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();
}

/**
 * @return array<string, array{0: callable(Workspace): void, 1: BillingStatus}>
 */
function billingStatusArrangements(): array
{
    return [
        'a trial reads Trial, never Pro' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save(),
            BillingStatus::Trialing,
        ],
        'a paid subscription reads Pro' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Subscribed,
        ],
        'a lapsed subscription reads Past due, not Pro' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->pastDue()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::PastDue,
        ],
        'a past-due subscription past its end date reads Subscription ended' => [
            function (Workspace $workspace): void {
                Subscription::factory()->pastDue()->create(['workspace_id' => $workspace->getKey(), 'ends_at' => now()->subDay()]);
            },
            BillingStatus::SubscriptionEnded,
        ],
        'an expired trial awaiting the nightly downgrade reads Trial ended, not Granted' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->subHour()])->save(),
            BillingStatus::TrialEnded,
        ],
        'a trial paused by the nightly downgrade still reads Trial ended' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Free, 'pro_trial_used_at' => now()->subDays(15)])->save(),
            BillingStatus::TrialEnded,
        ],
        'a trial that ended beside an abandoned checkout reads Trial ended' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['pro_trial_used_at' => now()->subDays(15)])->save();
                Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::TrialEnded,
        ],
        'a cancelled subscription reads Subscription ended' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['pro_trial_used_at' => now()->subMonths(3)])->save();
                Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::SubscriptionEnded,
        ],
        'a cancelled subscription followed by an abandoned checkout still reads Subscription ended' => [
            function (Workspace $workspace): void {
                Subscription::factory()->canceled()->create([
                    'workspace_id' => $workspace->getKey(),
                    'created_at' => now()->subMonths(2),
                    'updated_at' => now()->subMonths(2),
                ]);
                Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::SubscriptionEnded,
        ],
        'a cancelled subscriber granted Pro by hand reads Granted' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['plan' => Plan::Pro])->save();
                Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Granted,
        ],
        'a grandfathered workspace whose subscription ended reads Free (legacy)' => [
            function (Workspace $workspace): void {
                $workspace->forceFill(['hosted_free_grandfathered_at' => now()->subYear()])->save();
                Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Grandfathered,
        ],
        'a checkout abandoned without a trial reads Free' => [
            function (Workspace $workspace): void {
                Subscription::factory()->incompleteAndExpired()->create(['workspace_id' => $workspace->getKey()]);
            },
            BillingStatus::Free,
        ],
        'a hand-assigned plan reads Granted, not Pro' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Pro])->save(),
            BillingStatus::Granted,
        ],
        'an enterprise workspace reads Enterprise' => [
            fn (Workspace $workspace) => $workspace->forceFill(['plan' => Plan::Enterprise])->save(),
            BillingStatus::Enterprise,
        ],
        'a pre-billing workspace reads Free (legacy)' => [
            fn (Workspace $workspace) => $workspace->forceFill(['hosted_free_grandfathered_at' => now()])->save(),
            BillingStatus::Grandfathered,
        ],
        'a workspace with nothing bought reads Free' => [
            fn (Workspace $workspace): null => null,
            BillingStatus::Free,
        ],
    ];
}

it('labels a workspace by why it has its plan, not by the plan alone', function (callable $arrange, BillingStatus $expected): void {
    $workspace = billingStatusWorkspace();
    $arrange($workspace);

    expect($workspace->fresh()?->billingStatus())->toBe($expected);

    livewire(ListWorkspaces::class)
        ->assertCanSeeTableRecords([$workspace])
        ->assertSee($expected->getLabel())
        ->assertSeeHtml($expected->getDescription());
})->with(billingStatusArrangements());

it('filters workspaces by the badge they show, and by no other', function (): void {
    $arranged = [];

    foreach (billingStatusArrangements() as $name => [$arrange, $status]) {
        $workspace = billingStatusWorkspace();
        $arrange($workspace);
        $arranged[$name] = ['workspace' => $workspace->fresh(), 'status' => $status];
    }

    expect(collect($arranged)->map(fn (array $entry): string => $entry['status']->value)->unique()->values()->all())
        ->toEqualCanonicalizing(array_column(BillingStatus::cases(), 'value'));

    foreach ($arranged as $entry) {
        $others = collect($arranged)
            ->reject(fn (array $other): bool => $other['status'] === $entry['status'])
            ->map(fn (array $other): Workspace => $other['workspace'])
            ->values()
            ->all();

        livewire(ListWorkspaces::class)
            ->filterTable('billing_status', [$entry['status']->value])
            ->assertCanSeeTableRecords([$entry['workspace']])
            ->assertCanNotSeeTableRecords($others);
    }
});

it('filters on the subscription a workspace bills on now, not a superseded one', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->pastDue()->create([
        'workspace_id' => $workspace->getKey(),
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$workspace]);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$workspace]);
});

it('filters a workspace still inside its grace period as Pro, the way the badge reads it', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->unpaid()->create([
        'workspace_id' => $workspace->getKey(),
        'ends_at' => now()->addDays(5),
    ]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$workspace]);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$workspace]);
});

it('filters on the default subscription, ignoring a newer one of another type', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro])->save();

    Subscription::factory()->active()->create([
        'workspace_id' => $workspace->getKey(),
        'created_at' => now()->subMonths(2),
        'updated_at' => now()->subMonths(2),
    ]);

    Subscription::factory()->pastDue()->create([
        'workspace_id' => $workspace->getKey(),
        'type' => 'addon',
    ]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::Subscribed])
        ->assertCanSeeTableRecords([$workspace]);

    livewire(ListWorkspaces::class)
        ->filterTable('billing_status', [BillingStatus::PastDue])
        ->assertCanNotSeeTableRecords([$workspace]);
});

it('prefers a live subscription over a trial that has not run out yet', function (): void {
    $workspace = billingStatusWorkspace();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(5)])->save();
    Subscription::factory()->active()->create(['workspace_id' => $workspace->getKey()]);

    expect($workspace->fresh()?->billingStatus())->toBe(BillingStatus::Subscribed);
});

it('shows what a workspace said it tracks, and its use case details by label', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();
    $workspace->forceFill([
        'onboarding_use_case' => OnboardingUseCase::Sales,
        'onboarding_context' => ['outbound', 'partner_led'],
        'onboarding_other_use_case' => 'Wholesale buyers and distributors',
    ])->save();

    livewire(ViewWorkspace::class, ['record' => $workspace->getKey()])
        ->assertSuccessful()
        ->assertSee('Wholesale buyers and distributors')
        ->assertSee('Outbound')
        ->assertSee('Partner-led')
        ->assertDontSee('partner_led');
});

it('finds a workspace by the free text it gave for its use case', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();
    $workspace->forceFill([
        'onboarding_use_case' => OnboardingUseCase::Other,
        'onboarding_other_use_case' => 'Grant applications',
    ])->save();

    $other = User::factory()->withPersonalWorkspace()->create();

    livewire(ListWorkspaces::class)
        ->searchTable('Grant applications')
        ->assertCanSeeTableRecords([$workspace])
        ->assertCanNotSeeTableRecords([$other->ownedWorkspaces()->first()]);
});

it('offers owner impersonation to super administrators of a workspace that has an owner', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();
    $ownerless = Workspace::factory()->create();
    $ownerless->owner()->delete();

    livewire(ViewWorkspace::class, ['record' => $workspace->getKey()])
        ->assertActionVisible('impersonateOwner');

    livewire(ViewWorkspace::class, ['record' => $ownerless->getKey()])
        ->assertActionHidden('impersonateOwner');

    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');

    livewire(ViewWorkspace::class, ['record' => $workspace->getKey()])
        ->assertActionHidden('impersonateOwner');
});

it('lands the owner impersonation link in the viewed workspace', function (): void {
    $workspace = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->firstOrFail();

    $link = livewire(ViewWorkspace::class, ['record' => $workspace->getKey()])
        ->callAction('impersonateOwner')
        ->effects['redirect'];

    parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

    expect($link)->toStartWith(url()->getPublicUrl("impersonate/{$workspace->user_id}?"))
        ->and($query['workspace'])->toBe($workspace->getKey());
});

it('shows which assistant sent a workspace and what its owner asked, by label', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = $owner->ownedWorkspaces()->first();
    $workspace->forceFill([
        'onboarding_referral_source' => OnboardingReferralSource::AI,
        'onboarding_referral_detail' => 'chatgpt',
        'onboarding_referral_prompt' => 'A CRM my assistant can update',
    ])->save();

    livewire(ViewWorkspace::class, ['record' => $workspace->getKey()])
        ->assertSuccessful()
        ->assertSee('ChatGPT')
        ->assertSee('A CRM my assistant can update')
        ->assertDontSee('chatgpt');
});

it('filters workspaces by the assistant that sent them, and names it by label', function (): void {
    $fromClaude = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->first();
    $fromClaude->forceFill([
        'onboarding_referral_source' => OnboardingReferralSource::AI,
        'onboarding_referral_detail' => 'claude',
    ])->save();

    $fromGemini = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->first();
    $fromGemini->forceFill([
        'onboarding_referral_source' => OnboardingReferralSource::AI,
        'onboarding_referral_detail' => 'gemini',
    ])->save();

    livewire(ListWorkspaces::class)
        ->filterTable('onboarding_referral_detail', 'claude')
        ->assertCanSeeTableRecords([$fromClaude])
        ->assertCanNotSeeTableRecords([$fromGemini])
        ->assertTableColumnFormattedStateSet('onboarding_referral_detail', 'Claude', record: $fromClaude);
});

it('finds a workspace by what its owner asked the assistant', function (): void {
    $asked = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->first();
    $asked->forceFill([
        'onboarding_referral_source' => OnboardingReferralSource::AI,
        'onboarding_referral_detail' => 'claude',
        'onboarding_referral_prompt' => 'A CRM my assistant can update',
    ])->save();

    $other = User::factory()->withPersonalWorkspace()->create()->ownedWorkspaces()->first();

    livewire(ListWorkspaces::class)
        ->searchTable('assistant can update')
        ->assertCanSeeTableRecords([$asked])
        ->assertCanNotSeeTableRecords([$other]);
});

it('filters workspaces owned by a system administrator as internal', function (): void {
    $internal = OverviewData::workspaceOf(OverviewData::internalOwner());
    $external = OverviewData::workspaceOf(OverviewData::owner());
    $ownerless = Workspace::factory()->create(['user_id' => (string) Str::ulid()]);

    livewire(ListWorkspaces::class)
        ->filterTable('internal', true)
        ->assertCanSeeTableRecords([$internal])
        ->assertCanNotSeeTableRecords([$external, $ownerless]);

    livewire(ListWorkspaces::class)
        ->filterTable('internal', false)
        ->assertCanSeeTableRecords([$external, $ownerless])
        ->assertCanNotSeeTableRecords([$internal]);
});

it('creates overview fixture records under a sysadmin session without a creator or a stray user', function (): void {
    $owner = OverviewData::owner();
    $workspace = OverviewData::workspaceOf($owner);
    $usersBefore = User::query()->count();

    $sample = OverviewData::sampleRecord($workspace, now())->refresh();
    $own = OverviewData::ownRecord($workspace, $owner, now())->refresh();

    expect($sample->creator_id)->toBeNull()
        ->and($sample->account_owner_id)->toBeNull()
        ->and($sample->creation_source)->toBe(CreationSource::SAMPLE)
        ->and($own->creator_id)->toBe($owner->getKey())
        ->and($own->account_owner_id)->toBe($owner->getKey())
        ->and($own->creation_source)->toBe(CreationSource::WEB)
        ->and(User::query()->count())->toBe($usersBefore);
});

it('filters workspaces active in at least three of the last four complete weeks', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    $weekStart = CarbonImmutable::parse('2026-09-28');

    $habitOwner = OverviewData::owner(CarbonImmutable::parse('2026-08-20'));
    $habit = OverviewData::workspaceOf($habitOwner);
    OverviewData::ownRecord($habit, $habitOwner, $weekStart->subWeeks(1)->addDay());
    OverviewData::ownRecord($habit, $habitOwner, $weekStart->subWeeks(2)->addDay());
    OverviewData::typedMessage($habit, $habitOwner, $weekStart->subWeeks(4)->addDay());

    $twoWeeksOwner = OverviewData::owner(CarbonImmutable::parse('2026-08-20'));
    $twoWeeks = OverviewData::workspaceOf($twoWeeksOwner);
    OverviewData::ownRecord($twoWeeks, $twoWeeksOwner, $weekStart->subWeeks(1)->addDay());
    OverviewData::ownRecord($twoWeeks, $twoWeeksOwner, $weekStart->subWeeks(2)->addDay());

    $sampleOnly = OverviewData::workspaceOf(OverviewData::owner(CarbonImmutable::parse('2026-08-20')));
    foreach ([1, 2, 3] as $weeksAgo) {
        OverviewData::sampleRecord($sampleOnly, $weekStart->subWeeks($weeksAgo)->addDay());
    }

    $internalOwner = OverviewData::internalOwner();
    $internal = OverviewData::workspaceOf($internalOwner);
    foreach ([1, 2, 3] as $weeksAgo) {
        OverviewData::ownRecord($internal, $internalOwner, $weekStart->subWeeks($weeksAgo)->addDay());
    }

    livewire(ListWorkspaces::class)
        ->filterTable('formed_habit')
        ->assertCanSeeTableRecords([$habit])
        ->assertCanNotSeeTableRecords([$twoWeeks, $sampleOnly, $internal]);
});

it('counts own records whose creator was deleted toward a habit', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    $weekStart = CarbonImmutable::parse('2026-09-28');

    $workspace = OverviewData::workspaceOf(OverviewData::owner(CarbonImmutable::parse('2026-08-20')));
    foreach ([1, 2, 3] as $weeksAgo) {
        $at = $weekStart->subWeeks($weeksAgo)->addDay();

        Company::withoutEvents(fn (): Company => Company::factory()->create([
            'workspace_id' => $workspace->getKey(),
            'creator_id' => null,
            'account_owner_id' => null,
            'creation_source' => CreationSource::WEB,
            'created_at' => $at,
            'updated_at' => $at,
        ]));
    }

    livewire(ListWorkspaces::class)
        ->filterTable('formed_habit')
        ->assertCanSeeTableRecords([$workspace]);
});

function chargeChat(Workspace $workspace, string $model, int $credits): void
{
    AiCreditTransaction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $workspace->user_id,
        'idempotency_key' => 'test-'.Str::ulid(),
        'type' => AiCreditType::Chat,
        'model' => $model,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'credits_charged' => $credits,
        'metadata' => [],
        'created_at' => now(),
    ]);
}

it('filters trialing workspaces without own data that farm premium models or sit in an abuse timezone', function (): void {
    config()->set('system-admin.abuse_timezones', ['Asia/Tehran']);

    $byTimezone = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran'])));
    $byPremium = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    chargeChat($byPremium, 'claude-opus-5', 6);
    chargeChat($byPremium, 'claude-sonnet-5', 2);

    $genuineOwner = OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran']);
    $genuine = OverviewData::trial(OverviewData::workspaceOf($genuineOwner));
    OverviewData::ownRecord($genuine, $genuineOwner, now());

    $freeTehran = OverviewData::workspaceOf(OverviewData::owner(attributes: ['timezone' => 'Asia/Tehran']));
    $mostlyFree = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    chargeChat($mostlyFree, 'claude-sonnet-5', 9);
    chargeChat($mostlyFree, 'claude-opus-5', 3);

    livewire(ListWorkspaces::class)
        ->filterTable('abuse_suspect')
        ->assertCanSeeTableRecords([$byTimezone, $byPremium])
        ->assertCanNotSeeTableRecords([$genuine, $freeTehran, $mostlyFree]);
});

it('ends a trial now so the workspace pauses on the pay screen', function (): void {
    Feature::define(Billing::class, true);
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('endTrial')->table($workspace));

    $workspace->refresh();

    expect($workspace->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and(resolve(HostedWorkspaceAccess::class)->isPaused($workspace))->toBeTrue();
});

it('ends several trials at once and skips workspaces that are not trialing', function (): void {
    $trialA = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $trialB = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $free = OverviewData::workspaceOf(OverviewData::owner());

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$trialA, $trialB, $free])
        ->callAction(TestAction::make('endTrials')->table()->bulk())
        ->assertNotified('Ended 2 of 3 trials');

    expect($trialA->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and($trialB->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and($free->refresh()->trial_ends_at)->toBeNull();
});

it('reports success when every selected workspace is trialing', function (): void {
    $trialA = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));
    $trialB = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$trialA, $trialB])
        ->callAction(TestAction::make('endTrials')->table()->bulk())
        ->assertNotified('Trials ended');

    expect($trialA->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded)
        ->and($trialB->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded);
});

it('hides end trial on a workspace that is not trialing', function (): void {
    $free = OverviewData::workspaceOf(OverviewData::owner());

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('endTrial')->table($free));
});

it('hides end trial from an administrator without customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('endTrial')->table($workspace));
});

it('hides the end trials bulk action from an administrator without customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('endTrials')->table()->bulk());
});

it('warns instead of reporting success when no selected workspace is trialing', function (): void {
    $free = OverviewData::workspaceOf(OverviewData::owner());

    livewire(ListWorkspaces::class)
        ->selectTableRecords([$free])
        ->callAction(TestAction::make('endTrials')->table()->bulk())
        ->assertNotified('No trialing workspaces selected');
});

it('offers end trial on the workspace page', function (): void {
    $workspace = OverviewData::trial(OverviewData::workspaceOf(OverviewData::owner()));

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('endTrial');

    expect($workspace->refresh()->billingStatus())->toBe(BillingStatus::TrialEnded);
});

it('shows the journey of a workspace on its page', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-10-01 09:00:00'));
    $workspace = OverviewData::workspaceOf($owner);
    OverviewData::ownRecord($workspace, $owner, CarbonImmutable::parse('2026-10-02 09:00:00'));
    OverviewData::typedMessage($workspace, $owner, CarbonImmutable::parse('2026-10-03 09:00:00'));

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertSee('Journey')
        ->assertSee('Password')
        ->assertSee('Oct 2, 2026')
        ->assertSee('2 active days')
        ->assertDontSee('Last wizard step');
});

it('shows how many mailboxes a workspace connected and how many need attention on its journey', function (): void {
    $workspace = OverviewData::workspaceOf(OverviewData::owner());

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertSeeInOrder(['Connected mailboxes', 'None']);

    ConnectedAccount::factory()->create(['workspace_id' => $workspace]);
    ConnectedAccount::factory()->error()->create(['workspace_id' => $workspace]);
    ConnectedAccount::factory()->disconnected()->create(['workspace_id' => $workspace]);

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertSee('2 connected, 1 needs attention');
});

it('counts the last 30 calendar days, today included, as the journey active days', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    $owner = OverviewData::owner(CarbonImmutable::parse('2026-08-01'));
    $workspace = OverviewData::workspaceOf($owner);
    OverviewData::ownRecord($workspace, $owner, CarbonImmutable::parse('2026-09-15 12:00:00'));
    OverviewData::ownRecord($workspace, $owner, CarbonImmutable::parse('2026-09-16 12:00:00'));
    OverviewData::ownRecord($workspace, $owner, now());

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertSee('2 active days');
});

it('shows the journey of a workspace whose owner no longer exists', function (): void {
    $departed = OverviewData::owner();
    $workspace = OverviewData::workspaceOf($departed);
    OverviewData::ownRecord($workspace, $departed, now());
    $workspace->forceFill(['user_id' => (string) Str::ulid()])->save();

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertSee('Journey')
        ->assertSee('1 active day')
        ->assertSeeInOrder(['Signed up', "\u{2014}", 'Signup method', "\u{2014}", 'First own record']);
});
