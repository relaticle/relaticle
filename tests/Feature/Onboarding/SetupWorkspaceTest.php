<?php

declare(strict_types=1);

use App\Actions\Onboarding\ApplyStagePreset;
use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Actions\Onboarding\SaveOnboardingSharing;
use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Enums\CreationSource;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\OnboardingStep;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceRole;
use App\Features\EmailIntegration;
use App\Features\OnboardSeed;
use App\Features\SetupConversation;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Concerns\RunsInviteStep;
use App\Filament\Pages\Concerns\RunsMailboxSteps;
use App\Filament\Pages\Concerns\RunsUseCaseStep;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SetupWorkspace;
use App\Jobs\Email\SyncSubscriberJob;
use App\Livewire\App\Workspaces\Concerns\SendsWorkspaceInvitations;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\UserSocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use Filament\Facades\Filament;
use Filament\Support\Facades\FilamentView;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Js;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;
use Relaticle\OnboardSeed\ModelSeeders\CompanySeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(SetupWorkspace::class, RunsMailboxSteps::class, RunsUseCaseStep::class, RunsInviteStep::class, SendsWorkspaceInvitations::class, SaveOnboardingSharing::class, SaveOnboardingUseCase::class, ApplyStagePreset::class, MoveWorkspaceSetup::class);

beforeEach(function (): void {
    Feature::define(EmailIntegration::class, false);
});

function workspaceInSetup(User $user, string $name = 'Northwind Studio'): Workspace
{
    test()->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => $name])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->currentWorkspace;

    Filament::setTenant($workspace);

    return $workspace;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function connectedMailboxFor(User $user, Workspace $workspace, array $attributes = []): ConnectedAccount
{
    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'email_address' => 'olivia@northwind.test',
        ...$attributes,
    ]));
}

/**
 * @param  array<string, mixed>  $attributes
 */
function mailOf(User $user, Workspace $workspace, ConnectedAccount $account, EmailPrivacyTier $tier, array $attributes = []): Email
{
    return Email::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'connected_account_id' => $account->getKey(),
        'privacy_tier' => $tier,
        ...$attributes,
    ]);
}

/**
 * @return list<string>
 */
function emphasizedProviderButtons(Testable $setup): array
{
    preg_match_all('/<button\b[^>]*\bdata-provider="(?<provider>[a-z]+)"[^>]*>/s', $setup->html(), $buttons, PREG_SET_ORDER);

    return collect($buttons)
        ->filter(fn (array $button): bool => str_contains($button[0], 'data-emphasized="true"'))
        ->map(fn (array $button): string => $button['provider'])
        ->values()
        ->all();
}

function bindSeederFailingOnAQuery(): void
{
    app()->bind(CompanySeeder::class, fn (): ModelSeederInterface => new class implements ModelSeederInterface
    {
        public function seed(Workspace $workspace, Authenticatable $user): void
        {
            $level = DB::transactionLevel();
            $savepoint = $level > 1 ? "trans{$level}" : 'seeder_probe';

            if ($level === 1) {
                DB::unprepared("savepoint {$savepoint}");
            }

            try {
                DB::select('select * from a_table_that_does_not_exist');
            } catch (QueryException $exception) {
                DB::unprepared("rollback to savepoint {$savepoint}");

                throw $exception;
            }
        }

        public function customFields(): Collection
        {
            return collect();
        }

        public function initialize(): ModelSeederInterface
        {
            return $this;
        }
    });
}

function workspaceAtInvite(User $user, string $name = 'Northwind Studio'): Workspace
{
    $workspace = workspaceInSetup($user, $name);

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    $workspace = $workspace->fresh();

    Filament::setTenant($workspace);

    return $workspace;
}

function joinAsMember(Workspace $workspace, WorkspaceRole $role = WorkspaceRole::Member): User
{
    $member = User::factory()->create();

    $workspace->users()->attach($member, ['role' => $role->value]);

    test()->actingAs($member);
    Filament::setTenant($workspace);

    return $member;
}

function inviteLinkUrl(Workspace $workspace): string
{
    return route('workspaces.join', ['token' => $workspace->fresh()->invite_link_token]);
}

it('creates the workspace at the referral step and sends the owner to setup', function (): void {
    FilamentView::spa();

    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Northwind Studio'])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->currentWorkspace;

    expect($workspace->onboarding_step)->toBe(OnboardingStep::UseCase)
        ->and($workspace->onboarding_use_case)->toBeNull();

    $component->assertRedirect(SetupWorkspace::getUrl(['tenant' => $workspace]));

    expect($component->effects)->not->toHaveKey('redirectUsingNavigate');
});

it('asks for the use case on the setup page', function (): void {
    workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case_description'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case_hint'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.form.use_case_label'))
        ->assertDontSee('filament/pages/workspaces.create_workspace')
        ->assertDontSee('filament/pages/workspaces.setup_workspace')
        ->assertFormFieldExists('onboarding_use_case');
});

it('renders the setup page without the app topbar', function (): void {
    $workspace = workspaceInSetup(User::factory()->create());

    $this->get('/app/'.$workspace->slug.'/setup')
        ->assertSuccessful()
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'))
        ->assertDontSeeHtml('fi-simple-layout-header')
        ->assertDontSeeHtml('fi-topbar')
        ->assertDontSeeHtml('fi-sidebar');
});

it('requires a use case', function (): void {
    workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->call('saveUseCase')
        ->assertHasFormErrors(['onboarding_use_case' => 'required']);
});

it('stores the use case and moves on to the invite step', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors()
        ->assertRedirect(SetupWorkspace::getUrl(['tenant' => $workspace]));

    expect($workspace->fresh())
        ->onboarding_use_case->toBe(OnboardingUseCase::Sales)
        ->onboarding_context->toBe(['outbound'])
        ->onboarding_step->toBe(OnboardingStep::Invite);
});

it('stays on the setup page after the use case even when a setup conversation exists', function (): void {
    Feature::define(SetupConversation::class, true);

    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->call('saveUseCase')
        ->assertNotNotified(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
        ->assertRedirect(SetupWorkspace::getUrl(['tenant' => $workspace]));

    expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite);
});

it('lands an additional workspace on the dashboard once the invite step is done', function (): void {
    Feature::define(SetupConversation::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = workspaceInSetup($user, 'Second Corp');

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->call('saveUseCase')
        ->assertRedirect(SetupWorkspace::getUrl(['tenant' => $workspace]));

    livewire(SetupWorkspace::class)
        ->call('finish')
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));
});

it('stores the use case chosen after the wizard created the workspace', function (): void {
    $workspace = onboardWorkspace(
        User::factory()->create(),
        ['name' => 'Acme Corp'],
        ['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']],
    );

    expect($workspace)
        ->slug->toBe('acme-corp')
        ->onboarding_use_case->toBe(OnboardingUseCase::Sales)
        ->onboarding_step->toBeNull();
});

it('previews the pipeline stages the chosen use case creates', function (): void {
    workspaceInSetup(User::factory()->create());

    $setup = livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Recruiting->value])
        ->assertSee(array_keys(OnboardingUseCase::Recruiting->pipelineStages()));

    expect(array_column($setup->instance()->getPreview()['stages'], 'name'))
        ->toBe(array_keys(OnboardingUseCase::Recruiting->pipelineStages()));

    $setup
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Fundraising->value])
        ->assertSee(array_keys(OnboardingUseCase::Fundraising->pipelineStages()))
        ->assertDontSee('Sourced');
});

it('replaces the default stages with the preset of the chosen use case', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Recruiting->value, 'onboarding_context' => ['sourcing']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    $stage = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('code', OpportunityField::STAGE->value)
        ->sole();

    expect($stage->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(array_keys(OnboardingUseCase::Recruiting->stagePreset()));
});

it('keeps the stages of a workspace that already holds an opportunity', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    Opportunity::factory()->for($workspace)->create();

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Recruiting->value, 'onboarding_context' => ['sourcing']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    $stage = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('code', OpportunityField::STAGE->value)
        ->sole();

    expect($stage->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(OpportunityField::STAGE->getOptions());
});

it('seeds sample data at the use case step when no mailbox is connected', function (): void {
    Feature::define(OnboardSeed::class, true);

    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    expect(Company::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse();

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase');

    expect(Company::query()->where('workspace_id', $workspace->getKey())->where('creation_source', CreationSource::SAMPLE)->count())->toBe(4);
});

it('seeds no sample data for an owner who connected a mailbox', function (): void {
    Feature::define(OnboardSeed::class, true);

    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'user_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
    ]));

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase');

    expect(Company::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse();
});

it('keeps the use case and moves on to the invite step when the sample seeder fails', function (): void {
    Feature::define(OnboardSeed::class, true);
    bindSeederFailingOnAQuery();

    $workspace = workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Recruiting->value, 'onboarding_context' => ['sourcing']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    $stage = CustomField::query()->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('code', OpportunityField::STAGE->value)
        ->sole();

    expect($workspace->fresh())
        ->onboarding_use_case->toBe(OnboardingUseCase::Recruiting)
        ->onboarding_step->toBe(OnboardingStep::Invite)
        ->and($stage->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(array_keys(OnboardingUseCase::Recruiting->stagePreset()))
        ->and(Company::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse();
});

it('seeds no sample data for an additional workspace', function (): void {
    Feature::define(OnboardSeed::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = workspaceInSetup($user, 'Second Corp');

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse();
});

it('syncs the owner so the use case tag reaches the mailing list', function (): void {
    config()->set('mailcoach-sdk.enabled_subscribers_sync', true);
    Queue::fake([SyncSubscriberJob::class]);

    $user = User::factory()->create();
    workspaceInSetup($user);

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->call('saveUseCase');

    Queue::assertPushed(SyncSubscriberJob::class, fn (SyncSubscriberJob $job): bool => invade($job)->userId === (string) $user->getKey());
});

it('rejects a second submit instead of seeding twice', function (): void {
    Feature::define(OnboardSeed::class, true);

    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);
    $input = ['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']];

    resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, $input, null);

    expect(fn () => resolve(SaveOnboardingUseCase::class)->execute($user, $workspace->fresh(), $input, null))
        ->toThrow(HttpException::class);

    expect(Company::query()->where('workspace_id', $workspace->getKey())->count())->toBe(4);
});

it('sends anyone who opens setup on a finished workspace to the dashboard', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $this->actingAs($user);
    Filament::setTenant($workspace);

    livewire(SetupWorkspace::class)->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));
});

it('sends a member who opens the setup of an unfinished workspace to the dashboard', function (): void {
    $workspace = workspaceInSetup(User::factory()->create());
    $member = User::factory()->create();

    $this->actingAs($member);
    Filament::setTenant($workspace);

    livewire(SetupWorkspace::class)->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));
});

it('refuses to save a use case for someone who does not own the workspace', function (): void {
    $owner = User::factory()->create();
    $workspace = workspaceInSetup($owner);
    $member = User::factory()->create();

    expect(fn () => resolve(SaveOnboardingUseCase::class)->execute($member, $workspace, ['onboarding_use_case' => 'other'], null))
        ->toThrow(HttpException::class);
});

it('stores the free text a user gives for the Other use case', function (): void {
    $workspace = workspaceInSetup(User::factory()->create(), 'Parish Office');

    livewire(SetupWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'onboarding_other_use_case' => 'Church donors',
        ])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect($workspace->fresh()->onboarding_other_use_case)->toBe('Church donors');
});

it('caps the Other use case text at 120 characters', function (): void {
    workspaceInSetup(User::factory()->create(), 'Long Text Co');

    livewire(SetupWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'onboarding_other_use_case' => str_repeat('a', 121),
        ])
        ->call('saveUseCase')
        ->assertHasFormErrors(['onboarding_other_use_case' => 'max']);
});

it('drops the Other text once a named use case is chosen instead', function (): void {
    $workspace = workspaceInSetup(User::factory()->create(), 'Switched Co');

    livewire(SetupWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'onboarding_other_use_case' => 'Church donors',
        ])
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
        ])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect($workspace->fresh())
        ->onboarding_use_case->toBe(OnboardingUseCase::Sales)
        ->onboarding_other_use_case->toBeNull();
});

it('rejects Other text over 120 characters when the action runs directly', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user, 'Tampered Other Co');

    expect(fn () => resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, [
        'onboarding_use_case' => OnboardingUseCase::Other->value,
        'onboarding_other_use_case' => str_repeat('a', 121),
    ], null))->toThrow(ValidationException::class);

    expect($workspace->fresh())
        ->onboarding_use_case->toBeNull()
        ->onboarding_step->toBe(OnboardingStep::UseCase);
});

it('drops Other text when the action runs directly with a named use case', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user, 'Direct Action Co');

    resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, [
        'onboarding_use_case' => OnboardingUseCase::Sales->value,
        'onboarding_context' => ['outbound'],
        'onboarding_other_use_case' => 'Church donors',
    ], null);

    expect($workspace->fresh()->onboarding_other_use_case)->toBeNull();
});

it('stores no context for a use case that offers no sub-options', function (): void {
    $workspace = workspaceInSetup(User::factory()->create(), 'Blank Canvas');

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect($workspace->fresh()->onboarding_context)->toBeNull();
});

it('stores the sub-options picked for the use case', function (): void {
    $workspace = workspaceInSetup(User::factory()->create(), 'Context Co');

    livewire(SetupWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound', 'inbound'],
        ])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect($workspace->fresh()->onboarding_context)->toBe(['outbound', 'inbound']);
});

it('requires a sub-option for use cases that have them', function (): void {
    workspaceInSetup(User::factory()->create(), 'No Context Co');

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value])
        ->call('saveUseCase')
        ->assertHasFormErrors(['onboarding_context' => 'required']);
});

it('clears the sub-options when the use case changes', function (): void {
    $workspace = workspaceInSetup(User::factory()->create(), 'Switcher Co');

    livewire(SetupWorkspace::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['outbound'],
        ])
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Recruiting->value])
        ->assertFormSet(['onboarding_context' => []])
        ->fillForm(['onboarding_context' => ['sourcing']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect($workspace->fresh())
        ->onboarding_use_case->toBe(OnboardingUseCase::Recruiting)
        ->onboarding_context->toBe(['sourcing']);
});

it('rejects sub-options that belong to another use case when the action runs directly', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user, 'Foreign Context Co');

    expect(fn () => resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, [
        'onboarding_use_case' => OnboardingUseCase::Recruiting->value,
        'onboarding_context' => ['outbound'],
    ], null))->toThrow(ValidationException::class);

    expect($workspace->fresh()->onboarding_use_case)->toBeNull();
});

it('shows the free text only for the Other use case', function (): void {
    workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value])
        ->assertFormFieldHidden('onboarding_other_use_case')
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->assertFormFieldVisible('onboarding_other_use_case');
});

it('requires a use case for an additional workspace as well', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    workspaceInSetup($user, 'Second Workspace');

    livewire(SetupWorkspace::class)
        ->call('saveUseCase')
        ->assertHasFormErrors(['onboarding_use_case' => 'required']);
});

it('moves the setup only from the step it is on', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, OnboardingStep::UseCase, OnboardingStep::Invite);

    expect($moved)->toBeTrue()
        ->and($workspace->onboarding_step)->toBe(OnboardingStep::Invite)
        ->and($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite);
});

it('does not move the setup when the workspace is on another step', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, OnboardingStep::Sharing, OnboardingStep::Invite);

    expect($moved)->toBeFalse()
        ->and($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
});

it('does not move a finished workspace back into setup', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, OnboardingStep::UseCase, OnboardingStep::Invite);

    expect($moved)->toBeFalse()
        ->and($workspace->fresh()->onboarding_step)->toBeNull();
});

it('reloads the passed workspace when another request already moved the step', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);
    $stale = $workspace->fresh();

    $workspace->update(['onboarding_step' => OnboardingStep::Sharing]);

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $stale, OnboardingStep::UseCase, OnboardingStep::Invite);

    expect($moved)->toBeFalse()
        ->and($stale->onboarding_step)->toBe(OnboardingStep::Sharing);
});

it('refuses to move the setup for someone who does not own the workspace', function (): void {
    $workspace = workspaceInSetup(User::factory()->create());

    expect(fn () => resolve(MoveWorkspaceSetup::class)->execute(User::factory()->create(), $workspace, OnboardingStep::UseCase, OnboardingStep::Invite))
        ->toThrow(HttpException::class);

    expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
});

it('aborts the use case step for a workspace that already has a use case', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    $workspace->update(['onboarding_use_case' => OnboardingUseCase::Sales]);

    expect(fn () => resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, ['onboarding_use_case' => 'other'], null))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(409));

    expect($workspace->fresh())
        ->onboarding_use_case->toBe(OnboardingUseCase::Sales)
        ->onboarding_step->toBe(OnboardingStep::UseCase);
});

describe('connect email', function (): void {
    beforeEach(function (): void {
        Feature::define(EmailIntegration::class, true);
        config()->set('services.azure.client_id', 'azure-client');
    });

    it('starts a new workspace on the connect step', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        expect($workspace->onboarding_step)->toBe(OnboardingStep::Email);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.heading'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.google'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.microsoft'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.skip'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.preview.from_mailbox'));
    });

    it('offers only Google when no Microsoft client is configured', function (): void {
        config()->set('services.azure.client_id');
        workspaceInSetup(User::factory()->create());

        $setup = livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.google'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.email.microsoft'))
            ->assertSeeHtml('data-provider="gmail"')
            ->assertDontSeeHtml('data-provider="azure"');

        expect($setup->instance()->offeredProviders())->toBe(['gmail']);
    });

    it('offers both providers once the Microsoft client is configured', function (): void {
        workspaceInSetup(User::factory()->create());

        $setup = livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.google'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.microsoft'))
            ->assertSeeHtmlInOrder(['data-provider="gmail"', 'data-provider="azure"']);

        expect($setup->instance()->offeredProviders())->toBe(['gmail', 'azure']);
    });

    it('emphasizes no provider the install does not offer', function (): void {
        config()->set('services.azure.client_id');
        $user = User::factory()->create();
        UserSocialAccount::factory()->for($user)->create(['provider_name' => 'microsoft']);
        workspaceInSetup($user);

        $setup = livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.google'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.email.microsoft'));

        expect($setup->instance()->emphasizedProvider())->toBeNull()
            ->and($setup->instance()->offeredProviders())->toBe(['gmail']);
    });

    it('signs the connect link for the offered provider when it is clicked', function (string $provider): void {
        $workspace = workspaceInSetup(User::factory()->create());

        $setup = livewire(SetupWorkspace::class)->call('connectMailbox', $provider);

        assertRedirectedToMailboxOAuth($setup, $provider, $workspace);

        parse_str((string) parse_url($setup->effects['redirect'], PHP_URL_QUERY), $query);

        expect($query['return'] ?? null)->toBe(SetupWorkspace::getUrl(['tenant' => $workspace]))
            ->and($setup->effects)->not->toHaveKey('redirectUsingNavigate');
    })->with(['gmail', 'azure']);

    it('ignores a connect for a provider the install does not offer', function (string $provider): void {
        config()->set('services.azure.client_id');
        workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->call('connectMailbox', $provider)
            ->assertSuccessful()
            ->assertNoRedirect();
    })->with(['azure', 'yahoo', '']);

    it('ignores a connect when the email feature was switched off while the step was open', function (): void {
        workspaceInSetup(User::factory()->create());

        $setup = livewire(SetupWorkspace::class);

        Feature::define(EmailIntegration::class, false);
        Feature::flushCache();

        $setup->call('connectMailbox', 'gmail')
            ->assertSuccessful()
            ->assertNoRedirect();
    });

    it('ignores a connect once the workspace has moved on from the connect step', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        $setup = livewire(SetupWorkspace::class);

        $workspace->update(['onboarding_step' => OnboardingStep::UseCase]);

        $setup->call('connectMailbox', 'gmail')
            ->assertSuccessful()
            ->assertNoRedirect();
    });

    it('renders the connect buttons as Livewire actions that stay inside the page', function (): void {
        workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->assertSeeHtml("wire:click=\"connectMailbox('gmail')\"")
            ->assertSeeHtml("wire:click=\"connectMailbox('azure')\"")
            ->assertDontSeeHtml('wire:navigate')
            ->assertDontSeeHtml('/email-accounts/redirect/');
    });

    it('moves on to the use case when the owner skips', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->callAction('skipMailbox')
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('stays on the connect step until the owner confirms the skip', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->mountAction('skipMailbox')
            ->assertMountedActionModalSee([
                __('filament/pages/workspaces.setup_workspace.email.skip_confirm.heading'),
                __('filament/pages/workspaces.setup_workspace.email.benefit_records'),
            ]);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Email);
    });

    it('lets the owner go back to connect from the use case while no mailbox is connected', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->callAction('skipMailbox')
            ->assertSee(__('filament/pages/workspaces.create_workspace.actions.back'))
            ->call('backToMailbox')
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.heading'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Email);
    });

    it('stays quiet when back is called twice', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->callAction('skipMailbox')
            ->call('backToMailbox')
            ->assertSuccessful()
            ->call('backToMailbox')
            ->assertSuccessful();

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Email);
    });

    it('offers no way back to connect once a mailbox is connected', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);

        livewire(SetupWorkspace::class)->callAction('skipMailbox');

        ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'user_id' => $user->getKey(),
            'workspace_id' => $workspace->getKey(),
        ]));

        livewire(SetupWorkspace::class)
            ->assertDontSee(__('filament/pages/workspaces.create_workspace.actions.back'))
            ->call('backToMailbox')
            ->assertSuccessful();

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('offers no way back to connect when the feature is off', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)->callAction('skipMailbox');

        Feature::define(EmailIntegration::class, false);
        Feature::flushCache();

        livewire(SetupWorkspace::class)
            ->assertDontSee(__('filament/pages/workspaces.create_workspace.actions.back'))
            ->call('backToMailbox')
            ->assertSuccessful();

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('moves on to the use case when the feature is switched off mid-setup', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        Feature::define(EmailIntegration::class, false);
        Feature::flushCache();

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('moves on to the sharing step when a mailbox is already connected', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.heading'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);
    });

    it('puts the provider the owner signed up with first', function (string $socialProvider, string $expected, string $other): void {
        $user = User::factory()->create();
        UserSocialAccount::factory()->for($user)->create(['provider_name' => $socialProvider]);
        workspaceInSetup($user);

        $setup = livewire(SetupWorkspace::class);

        expect($setup->instance()->emphasizedProvider())->toBe($expected);

        $setup->assertSeeHtmlInOrder([
            'data-provider="'.$expected.'"',
            'data-provider="'.$other.'"',
        ]);
    })->with([
        ['google', 'gmail', 'azure'],
        ['microsoft', 'azure', 'gmail'],
    ]);

    it('emphasizes only the button of the provider the owner signed up with', function (string $socialProvider, string $expected): void {
        $user = User::factory()->create();
        UserSocialAccount::factory()->for($user)->create(['provider_name' => $socialProvider]);
        workspaceInSetup($user);

        expect(emphasizedProviderButtons(livewire(SetupWorkspace::class)))->toBe([$expected]);
    })->with([
        ['google', 'gmail'],
        ['microsoft', 'azure'],
    ]);

    it('emphasizes no button when nothing hints at a provider', function (): void {
        workspaceInSetup(User::factory()->create(['email' => 'olivia@northwind.test']));

        expect(emphasizedProviderButtons(livewire(SetupWorkspace::class)))->toBe([]);
    });

    it('lets the most recently linked provider win when both are linked', function (string $first, string $second, string $expected): void {
        $user = User::factory()->create();
        UserSocialAccount::factory()->for($user)->create(['provider_name' => $first, 'created_at' => now()->subDays(2)]);
        UserSocialAccount::factory()->for($user)->create(['provider_name' => $second, 'created_at' => now()->subDay()]);
        workspaceInSetup($user);

        expect(livewire(SetupWorkspace::class)->instance()->emphasizedProvider())->toBe($expected);
    })->with([
        ['google', 'microsoft', 'azure'],
        ['microsoft', 'google', 'gmail'],
    ]);

    it('refuses to save the use case while the workspace is still on the connect step', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
            ->call('saveUseCase')
            ->assertStatus(409);

        expect($workspace->fresh())
            ->onboarding_use_case->toBeNull()
            ->onboarding_other_use_case->toBeNull()
            ->onboarding_step->toBe(OnboardingStep::Email);
    });

    it('reads the provider from a consumer mail domain when there is no social sign-in', function (string $email, ?string $expected): void {
        workspaceInSetup(User::factory()->create(['email' => $email]));

        expect(livewire(SetupWorkspace::class)->instance()->emphasizedProvider())->toBe($expected);
    })->with([
        ['olivia@gmail.com', 'gmail'],
        ['olivia@outlook.com', 'azure'],
        ['olivia@northwind.test', null],
    ]);

    it('lists Google first with no emphasis when nothing hints at a provider', function (): void {
        workspaceInSetup(User::factory()->create(['email' => 'olivia@northwind.test']));

        livewire(SetupWorkspace::class)
            ->assertSeeHtmlInOrder(['data-provider="gmail"', 'data-provider="azure"']);
    });

    it('ignores a social account that is not a mailbox provider', function (): void {
        $user = User::factory()->create(['email' => 'olivia@northwind.test']);
        UserSocialAccount::factory()->for($user)->create(['provider_name' => 'github']);
        workspaceInSetup($user);

        expect(livewire(SetupWorkspace::class)->instance()->emphasizedProvider())->toBeNull();
    });

    it('lands on sharing after a successful connect, with participants only chosen', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.heading'))
            ->assertSee('olivia@northwind.test')
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.connected'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.syncing'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.description'))
            ->assertDontSee(__('filament/pages/workspaces.create_workspace.actions.back'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.preview.from_mailbox'))
            ->assertSeeHtml('wire:poll.5s')
            ->assertSet('sharingTier', EmailPrivacyTier::METADATA_ONLY->value);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);
    });

    it('offers exactly participants only and subject line', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        $setup = livewire(SetupWorkspace::class)
            ->assertSee(EmailPrivacyTier::METADATA_ONLY->getLabel())
            ->assertSee(EmailPrivacyTier::METADATA_ONLY->getDescription())
            ->assertSee('Subject and message stay private.')
            ->assertSee(EmailPrivacyTier::SUBJECT->getLabel())
            ->assertSee(EmailPrivacyTier::SUBJECT->getDescription())
            ->assertSee('The message stays private.')
            ->assertDontSee(EmailPrivacyTier::FULL->getLabel())
            ->assertDontSee(EmailPrivacyTier::PRIVATE->getLabel());

        expect(array_keys($setup->instance()->sharingOptions()))
            ->toBe([EmailPrivacyTier::METADATA_ONLY->value, EmailPrivacyTier::SUBJECT->value]);
    });

    it('preselects the level the owner would get, falling back to participants only', function (EmailPrivacyTier $workspaceDefault, EmailPrivacyTier $expected): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        $workspace->update(['default_email_sharing_tier' => $workspaceDefault]);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)->assertSet('sharingTier', $expected->value);
    })->with([
        [EmailPrivacyTier::METADATA_ONLY, EmailPrivacyTier::METADATA_ONLY],
        [EmailPrivacyTier::SUBJECT, EmailPrivacyTier::SUBJECT],
        [EmailPrivacyTier::FULL, EmailPrivacyTier::METADATA_ONLY],
        [EmailPrivacyTier::PRIVATE, EmailPrivacyTier::METADATA_ONLY],
    ]);

    it('stores the chosen level as the owner\'s own and moves on', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->set('sharingTier', EmailPrivacyTier::SUBJECT->value)
            ->call('saveSharing')
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::SUBJECT)
            ->and($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('applies the chosen level to the owner\'s mail that synced before the choice and to no other mail', function (EmailPrivacyTier $chosen, EmailPrivacyTier $before): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        $account = connectedMailboxFor($user, $workspace);
        $colleague = User::factory()->create();

        $ownMail = mailOf($user, $workspace, $account, $before);
        $customizedMail = mailOf($user, $workspace, $account, $before, ['privacy_tier_customized' => true]);
        $colleagueMail = mailOf($colleague, $workspace, connectedMailboxFor($colleague, $workspace, ['email_address' => 'sam@northwind.test']), $before);

        livewire(SetupWorkspace::class)
            ->set('sharingTier', $chosen->value)
            ->call('saveSharing');

        expect($ownMail->fresh()->privacy_tier)->toBe($chosen)
            ->and($customizedMail->fresh()->privacy_tier)->toBe($before)
            ->and($colleagueMail->fresh()->privacy_tier)->toBe($before);
    })->with([
        'subject' => [EmailPrivacyTier::SUBJECT, EmailPrivacyTier::METADATA_ONLY],
        'participants only' => [EmailPrivacyTier::METADATA_ONLY, EmailPrivacyTier::SUBJECT],
    ]);

    it('stores participants only as the owner\'s own level when the default choice is submitted', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)->call('saveSharing');

        expect($user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
            ->and($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('renders the sharing step without an address row once the mailbox is gone', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        $account = connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class);

        $account->update(['status' => EmailAccountStatus::DISCONNECTED]);

        livewire(SetupWorkspace::class)
            ->assertSuccessful()
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.heading'))
            ->assertDontSee('olivia@northwind.test')
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.sharing.connected'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.sharing.syncing'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);
    });

    it('refuses a level the step does not offer', function (string $tier): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->set('sharingTier', $tier)
            ->call('saveSharing')
            ->assertHasErrors('sharingTier');

        expect($user->fresh()->default_email_sharing_tier)->toBeNull()
            ->and($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);
    })->with([EmailPrivacyTier::FULL->value, EmailPrivacyTier::PRIVATE->value, 'nonsense']);

    it('leaves the owner\'s level alone when a stale tab saves after the setup moved on', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        $setup = livewire(SetupWorkspace::class);

        resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, OnboardingStep::Sharing, OnboardingStep::UseCase);

        $setup->set('sharingTier', EmailPrivacyTier::SUBJECT->value)
            ->call('saveSharing')
            ->assertSuccessful();

        expect($user->fresh()->default_email_sharing_tier)->toBeNull()
            ->and($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('skips sharing for an owner who already chose a level, and leaves it alone', function (): void {
        $user = User::factory()->create(['default_email_sharing_tier' => EmailPrivacyTier::FULL]);
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('moves on to the use case when the feature is switched off on the sharing step', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);

        Feature::define(EmailIntegration::class, false);
        Feature::flushCache();

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase);
    });

    it('offers no way back to connect after the sharing step', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->call('saveSharing')
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'))
            ->assertDontSee(__('filament/pages/workspaces.create_workspace.actions.back'));
    });

    it('shows how many people the mailbox has created so far', function (int $mailboxPeople, string $chip): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        People::factory()->for($workspace)->count($mailboxPeople)->create(['creation_source' => CreationSource::MAILBOX]);
        People::factory()->for($workspace)->create(['creation_source' => CreationSource::SAMPLE]);
        People::factory()->for($workspace)->create(['creation_source' => CreationSource::MAILBOX, 'deleted_at' => now()]);
        People::factory()->create(['creation_source' => CreationSource::MAILBOX]);

        $setup = livewire(SetupWorkspace::class);

        expect($setup->instance()->getPreview()['mailboxProgress'])->toBe($chip);

        $setup->assertSee($chip);
    })->with([
        'none yet' => [0, 'Reading your mailbox'],
        'one person' => [1, '1 person found'],
        'several people' => [3, '3 people found'],
    ]);

    it('skips sharing for an owner with a mailbox in another workspace, and leaves that mail alone', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = $user->currentWorkspace;
        $email = mailOf($user, $other, connectedMailboxFor($user, $other), EmailPrivacyTier::FULL);

        $workspace = workspaceInSetup($user, 'Second Corp');
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBeNull()
            ->and($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('still asks about sharing when the only other mailbox was removed, and leaves its mail alone', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = $user->currentWorkspace;
        $removed = connectedMailboxFor($user, $other);
        $email = mailOf($user, $other, $removed, EmailPrivacyTier::FULL);
        $removed->delete();

        $workspace = workspaceInSetup($user, 'Second Corp');
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.heading'))
            ->call('saveSharing')
            ->assertHasNoErrors();

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBe(EmailPrivacyTier::METADATA_ONLY)
            ->and(Email::query()->withoutGlobalScopes()->whereKey($email->getKey())->value('privacy_tier'))->toBe(EmailPrivacyTier::FULL);
    });

    it('skips sharing while another workspace holds a mailbox of the owner in any status', function (EmailAccountStatus $status): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = $user->currentWorkspace;
        $email = mailOf($user, $other, connectedMailboxFor($user, $other, ['status' => $status]), EmailPrivacyTier::FULL);

        $workspace = workspaceInSetup($user, 'Second Corp');
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBeNull()
            ->and(Email::query()->withoutGlobalScopes()->whereKey($email->getKey())->value('privacy_tier'))->toBe(EmailPrivacyTier::FULL);
    })->with([EmailAccountStatus::DISCONNECTED, EmailAccountStatus::ERROR, EmailAccountStatus::REAUTH_REQUIRED]);

    it('moves on from sharing when the owner has stored a level elsewhere since', function (EmailPrivacyTier $stored): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);

        $user->update(['default_email_sharing_tier' => $stored]);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBe($stored);
    })->with([EmailPrivacyTier::PRIVATE, EmailPrivacyTier::FULL]);

    it('moves on from sharing when the owner has connected a mailbox elsewhere since', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = $user->currentWorkspace;
        $workspace = workspaceInSetup($user, 'Second Corp');
        connectedMailboxFor($user, $workspace);

        livewire(SetupWorkspace::class);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);

        mailOf($user, $other, connectedMailboxFor($user, $other), EmailPrivacyTier::FULL);

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBeNull();
    });

    it('saves nothing when a stale tab continues after the owner stored a level elsewhere', function (EmailPrivacyTier $stored): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        $account = connectedMailboxFor($user, $workspace);
        $email = mailOf($user, $workspace, $account, EmailPrivacyTier::METADATA_ONLY);

        $setup = livewire(SetupWorkspace::class);

        $user->update(['default_email_sharing_tier' => $stored]);

        $setup->set('sharingTier', EmailPrivacyTier::SUBJECT->value)->call('saveSharing');

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBe($stored)
            ->and($email->fresh()->privacy_tier)->toBe(EmailPrivacyTier::METADATA_ONLY);
    })->with([EmailPrivacyTier::PRIVATE, EmailPrivacyTier::FULL]);

    it('saves nothing when a stale tab continues after the owner connected a mailbox elsewhere', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        $other = $user->currentWorkspace;
        $workspace = workspaceInSetup($user, 'Second Corp');
        connectedMailboxFor($user, $workspace);

        $setup = livewire(SetupWorkspace::class);

        $otherEmail = mailOf($user, $other, connectedMailboxFor($user, $other), EmailPrivacyTier::FULL);

        $setup->set('sharingTier', EmailPrivacyTier::METADATA_ONLY->value)->call('saveSharing');

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::UseCase)
            ->and($user->fresh()->default_email_sharing_tier)->toBeNull()
            ->and($otherEmail->fresh()->privacy_tier)->toBe(EmailPrivacyTier::FULL);
    });

    it('keeps the setup on sharing when saving the level fails', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        $setup = livewire(SetupWorkspace::class)->set('sharingTier', EmailPrivacyTier::SUBJECT->value);

        Event::listen('eloquent.updating: '.User::class, fn (): never => throw new RuntimeException('lock timeout'));

        expect(fn () => $setup->call('saveSharing'))->toThrow(RuntimeException::class);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing)
            ->and($user->fresh()->default_email_sharing_tier)->toBeNull();
    });

    it('refuses to save a level the sharing step does not offer when the action runs directly', function (EmailPrivacyTier $tier): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);
        livewire(SetupWorkspace::class);

        expect(fn () => resolve(SaveOnboardingSharing::class)->execute($user, $workspace, $tier))
            ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(422));

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing)
            ->and($user->fresh()->default_email_sharing_tier)->toBeNull();
    })->with([EmailPrivacyTier::FULL, EmailPrivacyTier::PRIVATE]);

    it('refuses to save a sharing level for someone who does not own the workspace', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);
        livewire(SetupWorkspace::class);
        $member = User::factory()->create();

        expect(fn () => resolve(SaveOnboardingSharing::class)->execute($member, $workspace, EmailPrivacyTier::SUBJECT))
            ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(403));

        expect($member->fresh()->default_email_sharing_tier)->toBeNull()
            ->and($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Sharing);
    });

    it('groups the two sharing options so the arrow keys move between them', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace);

        $html = livewire(SetupWorkspace::class)->html();

        expect(substr_count($html, 'type="radio"'))->toBe(2)
            ->and(substr_count($html, 'name="sharingTier"'))->toBe(2);
    });

    it('claims no sync for a mailbox that is not active', function (EmailAccountStatus $status): void {
        $user = User::factory()->create();
        $workspace = workspaceInSetup($user);
        connectedMailboxFor($user, $workspace, ['status' => $status]);

        $setup = livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.heading'))
            ->assertSee('olivia@northwind.test')
            ->assertSee(__('filament/pages/workspaces.setup_workspace.sharing.description'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.sharing.connected'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.sharing.syncing'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.preview.from_mailbox'));

        expect($setup->instance()->getPreview())->not->toHaveKey('mailboxProgress');
    })->with([EmailAccountStatus::ERROR, EmailAccountStatus::REAUTH_REQUIRED]);

    it('promises a sharing choice on the connect step to an owner who will be asked', function (): void {
        workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.privacy'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.email.privacy_existing'));
    });

    it('promises no sharing choice on the connect step to an owner who has a stored level', function (): void {
        workspaceInSetup(User::factory()->create(['default_email_sharing_tier' => EmailPrivacyTier::PRIVATE]));

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.privacy_existing'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.email.privacy'));
    });

    it('promises no sharing choice on the connect step to an owner with a mailbox elsewhere', function (): void {
        $user = User::factory()->withPersonalWorkspace()->create();
        connectedMailboxFor($user, $user->currentWorkspace);
        workspaceInSetup($user, 'Second Corp');

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.email.privacy_existing'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.email.privacy'));
    });
});

it('starts on the use case when the email feature is off', function (): void {
    $workspace = workspaceInSetup(User::factory()->create());

    expect($workspace->onboarding_step)->toBe(OnboardingStep::UseCase);
});

describe('invite team', function (): void {
    it('shows the invite step after the use case', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        expect($workspace->onboarding_step)->toBe(OnboardingStep::Invite);

        $setup = livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.invite.heading'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.invite.description'))
            ->assertSee(__('filament/pages/workspaces.setup_workspace.invite.link_label'))
            ->assertSee(__('filament/pages/workspaces.create_workspace.actions.get_started'))
            ->assertDontSee(__('filament/pages/workspaces.create_workspace.headings.use_case'))
            ->assertDontSee(__('filament/pages/workspaces.create_workspace.actions.back'))
            ->assertDontSee('filament/pages/workspaces.setup_workspace')
            ->assertFormFieldExists('emails')
            ->assertFormFieldExists('role')
            ->assertFormSet(['role' => WorkspaceRole::Member->value]);

        expect($setup->instance()->previewPanel())->toBe('members');
    });

    it('finishes with nothing typed', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceAtInvite($user);

        livewire(SetupWorkspace::class)
            ->call('finish')
            ->assertHasNoFormErrors()
            ->assertNotified(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
            ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

        expect($workspace->fresh()->onboarding_step)->toBeNull()
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('invites every pasted address with the chosen role and finishes', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceAtInvite($user);

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => "maya@northwind.test, leo@northwind.test;\nsam@northwind.test", 'role' => WorkspaceRole::Admin->value])
            ->call('finish')
            ->assertHasNoFormErrors()
            ->assertNotified(__('workspaces.notifications.workspace_invitation_sent.success'));

        expect($workspace->workspaceInvitations()->pluck('role', 'email')->all())->toEqual([
            'maya@northwind.test' => WorkspaceRole::Admin->value,
            'leo@northwind.test' => WorkspaceRole::Admin->value,
            'sam@northwind.test' => WorkspaceRole::Admin->value,
        ])->and($workspace->fresh()->onboarding_step)->toBeNull();
    });

    it('invites as a member unless another role is chosen', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@northwind.test'])
            ->call('finish')
            ->assertHasNoFormErrors();

        expect($workspace->workspaceInvitations()->sole()->role)->toBe(WorkspaceRole::Member->value);
    });

    it('invites an address once when it is pasted twice in different case', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'Maya@northwind.test, maya@northwind.test'])
            ->call('finish')
            ->assertHasNoFormErrors()
            ->assertNotNotified(__('workspaces.notifications.some_invites_failed.title'));

        expect($workspace->workspaceInvitations()->pluck('email')->all())->toBe(['maya@northwind.test']);
    });

    it('refuses a role that is not on offer', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@northwind.test', 'role' => 'owner'])
            ->call('finish')
            ->assertHasFormErrors(['role']);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('stays on the step when more addresses are pasted than one send allows', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());
        $emails = collect(range(1, 11))->map(fn (int $n): string => "person{$n}@northwind.test")->implode(', ');

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => $emails])
            ->call('finish')
            ->assertHasFormErrors(['emails']);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('stays on the step and sends nothing when one pasted address is malformed', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@northwind.test, leo.northwind.test'])
            ->call('finish')
            ->assertHasFormErrors(['emails'])
            ->assertNotNotified(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
            ->assertNoRedirect();

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('stays on the step for an address longer than an invitation can hold', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => str_repeat('a', 250).'@northwind.test'])
            ->call('finish')
            ->assertHasFormErrors(['emails']);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('finishes and warns when the invitations cannot be sent', function (): void {
        Exceptions::fake();
        $workspace = workspaceAtInvite(User::factory()->create());

        Event::listen('eloquent.creating: '.WorkspaceInvitation::class, fn (): never => throw new RuntimeException('queue is down'));

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@northwind.test'])
            ->call('finish')
            ->assertHasNoFormErrors()
            ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

        $notifications = collect(session('filament.notifications'));

        Exceptions::assertReported(RuntimeException::class);

        expect($notifications->pluck('title', 'status')->all())->toEqual([
            'warning' => __('filament/pages/workspaces.setup_workspace.invite.not_sent.title'),
            'success' => __('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'),
        ])
            ->and($notifications->firstWhere('status', 'warning')['body'])->toBe(__('filament/pages/workspaces.setup_workspace.invite.not_sent.body'))
            ->and($workspace->fresh()->onboarding_step)->toBeNull()
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('invites as a member when the submitted role is blank', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->set('data.emails', 'maya@northwind.test')
            ->set('data.role', '')
            ->call('finish')
            ->assertHasNoFormErrors();

        expect($workspace->workspaceInvitations()->sole()->role)->toBe(WorkspaceRole::Member->value);
    });

    it('finishes with only separators typed even while rate limited', function (string $separators): void {
        $user = User::factory()->create();
        $workspace = workspaceAtInvite($user);

        RateLimiter::increment('invite-workspace-members:'.$user->id, 60, 20);

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => $separators])
            ->call('finish')
            ->assertHasNoFormErrors();

        expect($workspace->fresh()->onboarding_step)->toBeNull()
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    })->with([',,,', ';']);

    it('stays on the step for a disposable address', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@mailinator.com'])
            ->call('finish')
            ->assertHasFormErrors(['emails']);

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite);
    });

    it('finishes and reports the addresses the invite action rejects', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceAtInvite($user);
        $teammate = User::factory()->create(['email' => 'leo@northwind.test']);
        $workspace->users()->attach($teammate, ['role' => WorkspaceRole::Member->value]);

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@northwind.test, leo@northwind.test'])
            ->call('finish')
            ->assertHasNoFormErrors()
            ->assertNotified(__('workspaces.notifications.some_invites_failed.title'));

        expect($workspace->workspaceInvitations()->pluck('email')->all())->toBe(['maya@northwind.test'])
            ->and($workspace->fresh()->onboarding_step)->toBeNull();
    });

    it('stays on the step when the owner has hit the invitation rate limit', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceAtInvite($user);

        RateLimiter::increment('invite-workspace-members:'.$user->id, 60, 20);

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@northwind.test'])
            ->call('finish')
            ->assertHasFormErrors(['emails'])
            ->assertNoRedirect();

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('finishes with nothing typed even while rate limited', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceAtInvite($user);

        RateLimiter::increment('invite-workspace-members:'.$user->id, 60, 20);

        livewire(SetupWorkspace::class)
            ->call('finish')
            ->assertHasNoFormErrors();

        expect($workspace->fresh()->onboarding_step)->toBeNull();
    });

    it('lands a first workspace on its setup conversation', function (): void {
        Feature::define(SetupConversation::class, true);

        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->call('finish')
            ->assertNotified(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
            ->assertRedirect(ChatConversation::getUrl([
                'conversationId' => $workspace->setupConversation->id,
                'tenant' => $workspace,
            ]));
    });

    it('sends nothing and goes back to setup when the workspace already finished', function (): void {
        $user = User::factory()->create();
        $workspace = workspaceAtInvite($user);

        $staleTab = livewire(SetupWorkspace::class)
            ->fillForm(['emails' => 'maya@northwind.test']);

        resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, OnboardingStep::Invite, null);

        $staleTab->call('finish')
            ->assertNotNotified(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
            ->assertRedirect(SetupWorkspace::getUrl(['tenant' => $workspace]));

        expect($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('refuses to finish for an admin who does not own the workspace', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());
        $ownerSetup = livewire(SetupWorkspace::class);

        joinAsMember($workspace, WorkspaceRole::Admin);

        $ownerSetup
            ->set('data.emails', 'maya@northwind.test')
            ->call('finish')
            ->assertForbidden();

        expect($workspace->fresh()->onboarding_step)->toBe(OnboardingStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('offers the live invite link for copying in the browser from the first render', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.invite.copy_link'))
            ->assertSee(__('workspaces.invite_link.copied'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.invite.create_link'))
            ->assertSeeHtml(e('navigator.clipboard.writeText('.Js::from(inviteLinkUrl($workspace)).')'));
    });

    it('offers to create a link once the old one is gone, and then offers it for copying', function (string $reason): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        match ($reason) {
            'expired' => $this->travelTo(now()->addDays(Workspace::INVITE_LINK_TTL_DAYS + 1)),
            'disabled' => $workspace->disableInviteLink(),
        };

        $oldToken = $workspace->fresh()->invite_link_token;

        $setup = livewire(SetupWorkspace::class)
            ->assertSee(__('filament/pages/workspaces.setup_workspace.invite.create_link'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.invite.copy_link'))
            ->assertSeeHtml('wire:target="createInviteLink"')
            ->call('createInviteLink')
            ->assertSee(__('filament/pages/workspaces.setup_workspace.invite.copy_link'))
            ->assertDontSee(__('filament/pages/workspaces.setup_workspace.invite.create_link'));

        $fresh = $workspace->fresh();

        expect($fresh->invite_link_token)->not->toBe($oldToken)
            ->and($fresh->isInviteLinkTokenExpired())->toBeFalse();

        $setup->assertSeeHtml(e('navigator.clipboard.writeText('.Js::from(inviteLinkUrl($workspace)).')'));
    })->with(['expired', 'disabled']);

    it('never rotates a live invite link', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());
        $token = $workspace->invite_link_token;

        livewire(SetupWorkspace::class)->call('createInviteLink');

        expect($workspace->fresh()->invite_link_token)->toBe($token);
    });

    it('creates no link before the invite step', function (): void {
        $workspace = workspaceInSetup(User::factory()->create());
        $workspace->disableInviteLink();

        livewire(SetupWorkspace::class)->call('createInviteLink');

        expect($workspace->fresh()->hasInviteLink())->toBeFalse();
    });

    it('refuses to create a link for a member without the capability', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());
        $workspace->disableInviteLink();
        $ownerSetup = livewire(SetupWorkspace::class);

        joinAsMember($workspace, WorkspaceRole::Member);

        $ownerSetup
            ->call('createInviteLink')
            ->assertForbidden();

        expect($workspace->fresh()->hasInviteLink())->toBeFalse();
    });

    it('returns no invite link to a member who replays the owner snapshot', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());
        $ownerSetup = livewire(SetupWorkspace::class);

        joinAsMember($workspace, WorkspaceRole::Member);

        $ownerSetup->call('inviteLinkUrl')->assertReturned(null);
    });

    it('gives the finish button and the link button their own loading target', function (): void {
        workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)->assertSeeHtml('wire:target="finish"');
    });
});
