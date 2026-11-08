<?php

declare(strict_types=1);

use App\Actions\Onboarding\ApplyStagePreset;
use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Enums\CreationSource;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\OnboardingUseCase;
use App\Enums\WorkspaceRole;
use App\Enums\WorkspaceSetupStep;
use App\Features\EmailIntegration;
use App\Features\OnboardSeed;
use App\Features\SetupConversation;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\Concerns\RunsInviteStep;
use App\Filament\Pages\Concerns\RunsUseCaseStep;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SetupWorkspace;
use App\Jobs\Email\SyncSubscriberJob;
use App\Livewire\App\Workspaces\Concerns\SendsWorkspaceInvitations;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
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
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;
use Relaticle\OnboardSeed\ModelSeeders\CompanySeeder;
use Relaticle\OnboardSeed\ModelSeeders\PeopleSeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(SetupWorkspace::class, RunsUseCaseStep::class, RunsInviteStep::class, SendsWorkspaceInvitations::class, SaveOnboardingUseCase::class, ApplyStagePreset::class, MoveWorkspaceSetup::class);

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

it('creates the workspace at the use case step and sends the owner to setup', function (): void {
    FilamentView::spa();

    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Northwind Studio'])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->currentWorkspace;

    expect($workspace->onboarding_step)->toBe(WorkspaceSetupStep::UseCase)
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

it('clears the required error once the owner picks a use case', function (): void {
    workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->call('saveUseCase')
        ->assertHasFormErrors(['onboarding_use_case' => 'required'])
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->assertHasNoFormErrors(['onboarding_use_case']);
});

it('clears the required details error once the owner picks a detail', function (): void {
    workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Recruiting->value])
        ->call('saveUseCase')
        ->assertHasFormErrors(['onboarding_context' => 'required'])
        ->fillForm(['onboarding_context' => ['sourcing']])
        ->assertHasNoFormErrors(['onboarding_context']);
});

it('gives the use case button its own loading target', function (): void {
    workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)->assertSeeHtml('wire:target="saveUseCase"');
});

it('labels the use case button Continue because the invite step follows', function (): void {
    workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.use_case'))
        ->assertDontSee(__('filament/pages/workspaces.create_workspace.actions.get_started'));
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
        ->onboarding_step->toBe(WorkspaceSetupStep::Invite);
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

    expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite);
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

it('seeds sample data at the use case step when there is no setup chat to ask', function (): void {
    Feature::define(OnboardSeed::class, true);
    Feature::define(SetupConversation::class, false);

    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    expect(Company::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse();

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase');

    expect(Company::query()->where('workspace_id', $workspace->getKey())->where('creation_source', CreationSource::SAMPLE)->count())->toBe(4);
});

it('leaves the sample data to the setup chat when it will ask where the clients live', function (): void {
    Feature::define(OnboardSeed::class, true);
    Feature::define(SetupConversation::class, true);

    $workspace = workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect(Company::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse()
        ->and($workspace->fresh()->onboarding_data_source)->toBeNull();
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
        ->onboarding_step->toBe(WorkspaceSetupStep::Invite)
        ->and($stage->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(array_keys(OnboardingUseCase::Recruiting->stagePreset()))
        ->and(Company::query()->where('workspace_id', $workspace->getKey())->exists())->toBeFalse();
});

it('leaves no partial sample set when the sample seeder fails halfway', function (): void {
    Feature::define(OnboardSeed::class, true);
    app()->bind(PeopleSeeder::class, fn (): ModelSeederInterface => new class implements ModelSeederInterface
    {
        public function seed(Workspace $workspace, Authenticatable $user): void
        {
            throw new RuntimeException('The people fixtures could not be read.');
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

    $workspace = workspaceInSetup(User::factory()->create());

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors();

    expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
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

it('ignores a second submit instead of seeding twice', function (): void {
    Feature::define(OnboardSeed::class, true);

    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);
    $input = ['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']];

    resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, $input, null);

    expect(resolve(SaveOnboardingUseCase::class)->execute($user, $workspace->fresh(), $input, null))->toBeFalse();

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
        ->onboarding_step->toBe(WorkspaceSetupStep::UseCase);
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

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, WorkspaceSetupStep::UseCase, WorkspaceSetupStep::Invite);

    expect($moved)->toBeTrue()
        ->and($workspace->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
        ->and($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite);
});

it('does not move the setup when the workspace is on another step', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, WorkspaceSetupStep::Invite, null);

    expect($moved)->toBeFalse()
        ->and($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::UseCase);
});

it('does not move a finished workspace back into setup', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, WorkspaceSetupStep::UseCase, WorkspaceSetupStep::Invite);

    expect($moved)->toBeFalse()
        ->and($workspace->fresh()->onboarding_step)->toBeNull();
});

it('reloads the passed workspace when another request already moved the step', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);
    $stale = $workspace->fresh();

    $workspace->update(['onboarding_step' => WorkspaceSetupStep::Invite]);

    $moved = resolve(MoveWorkspaceSetup::class)->execute($user, $stale, WorkspaceSetupStep::UseCase, WorkspaceSetupStep::Invite);

    expect($moved)->toBeFalse()
        ->and($stale->onboarding_step)->toBe(WorkspaceSetupStep::Invite);
});

it('refuses to move the setup for someone who does not own the workspace', function (): void {
    $workspace = workspaceInSetup(User::factory()->create());

    expect(fn () => resolve(MoveWorkspaceSetup::class)->execute(User::factory()->create(), $workspace, WorkspaceSetupStep::UseCase, WorkspaceSetupStep::Invite))
        ->toThrow(HttpException::class);

    expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::UseCase);
});

it('leaves a workspace that already has a use case as it is', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    $workspace->update(['onboarding_use_case' => OnboardingUseCase::Sales]);

    expect(resolve(SaveOnboardingUseCase::class)->execute($user, $workspace, ['onboarding_use_case' => 'other'], null))->toBeFalse();

    expect($workspace->fresh())
        ->onboarding_use_case->toBe(OnboardingUseCase::Sales)
        ->onboarding_step->toBe(WorkspaceSetupStep::UseCase);
});

describe('the wizard order', function (): void {
    it('starts a workspace at the use case step even when a mailbox could connect', function (): void {
        Feature::define(EmailIntegration::class, true);
        config()->set('services.azure.client_id', 'azure-client');

        $workspace = workspaceInSetup(User::factory()->create());

        expect($workspace->onboarding_step)->toBe(WorkspaceSetupStep::UseCase)
            ->and($workspace->onboarding_data_source)->toBeNull();
    });

    it('walks the use case, then the invite step, then the setup chat', function (): void {
        Feature::define(SetupConversation::class, true);
        Feature::define(EmailIntegration::class, true);
        config()->set('services.azure.client_id', 'azure-client');

        $workspace = workspaceInSetup(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->assertFormFieldExists('onboarding_use_case')
            ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
            ->call('saveUseCase')
            ->assertHasNoFormErrors();

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite);

        Filament::setTenant($workspace->fresh());

        livewire(SetupWorkspace::class)
            ->assertFormFieldExists('emails')
            ->call('finish')
            ->assertRedirect(ChatConversation::getUrl([
                'conversationId' => $workspace->setupConversation->id,
                'tenant' => $workspace,
            ]));

        expect($workspace->fresh())
            ->onboarding_step->toBeNull()
            ->onboarding_data_source->toBeNull();
    });
});

describe('invite team', function (): void {
    it('shows the invite step after the use case', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        expect($workspace->onboarding_step)->toBe(WorkspaceSetupStep::Invite);

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
            ->assertNotified(__('workspaces.notifications.workspace_invitation_sent.success_many', ['count' => 3]));

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

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('stays on the step when more addresses are pasted than one send allows', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());
        $emails = collect(range(1, 11))->map(fn (int $n): string => "person{$n}@northwind.test")->implode(', ');

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => $emails])
            ->call('finish')
            ->assertHasFormErrors(['emails']);

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
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

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
            ->and($workspace->workspaceInvitations()->count())->toBe(0);
    });

    it('stays on the step for an address longer than an invitation can hold', function (): void {
        $workspace = workspaceAtInvite(User::factory()->create());

        livewire(SetupWorkspace::class)
            ->fillForm(['emails' => str_repeat('a', 250).'@northwind.test'])
            ->call('finish')
            ->assertHasFormErrors(['emails']);

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
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

        $notifications = flashedNotifications();

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

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite);
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

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
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

        resolve(MoveWorkspaceSetup::class)->execute($user, $workspace, WorkspaceSetupStep::Invite, null);

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

        expect($workspace->fresh()->onboarding_step)->toBe(WorkspaceSetupStep::Invite)
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
