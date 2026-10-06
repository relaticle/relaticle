<?php

declare(strict_types=1);

use App\Actions\Onboarding\ApplyStagePreset;
use App\Actions\Onboarding\MoveWorkspaceSetup;
use App\Actions\Onboarding\SaveOnboardingUseCase;
use App\Enums\CreationSource;
use App\Enums\CustomFields\OpportunityField;
use App\Enums\OnboardingStep;
use App\Enums\OnboardingUseCase;
use App\Features\EmailIntegration;
use App\Features\OnboardSeed;
use App\Features\SetupConversation;
use App\Filament\Pages\ChatConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SetupWorkspace;
use App\Jobs\Email\SyncSubscriberJob;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\OnboardSeed\Contracts\ModelSeederInterface;
use Relaticle\OnboardSeed\ModelSeeders\CompanySeeder;
use Symfony\Component\HttpKernel\Exception\HttpException;

mutates(SetupWorkspace::class, SaveOnboardingUseCase::class, ApplyStagePreset::class, MoveWorkspaceSetup::class);

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

it('creates the workspace at the referral step and sends the owner to setup', function (): void {
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

it('stores the use case and finishes setup', function (): void {
    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Sales->value, 'onboarding_context' => ['outbound']])
        ->call('saveUseCase')
        ->assertHasNoFormErrors()
        ->assertNotified(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
        ->assertRedirect(Dashboard::getUrl(['tenant' => $workspace]));

    expect($workspace->fresh())
        ->onboarding_use_case->toBe(OnboardingUseCase::Sales)
        ->onboarding_context->toBe(['outbound'])
        ->onboarding_step->toBeNull();
});

it('lands a first workspace on its setup conversation', function (): void {
    Feature::define(SetupConversation::class, true);

    $user = User::factory()->create();
    $workspace = workspaceInSetup($user);

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->call('saveUseCase')
        ->assertNotified(__('filament/pages/workspaces.create_workspace.notifications.workspace_created.title'))
        ->assertRedirect(ChatConversation::getUrl([
            'conversationId' => $workspace->setupConversation->id,
            'tenant' => $workspace,
        ]));
});

it('lands an additional workspace on the dashboard', function (): void {
    Feature::define(SetupConversation::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = workspaceInSetup($user, 'Second Corp');

    livewire(SetupWorkspace::class)
        ->fillForm(['onboarding_use_case' => OnboardingUseCase::Other->value])
        ->call('saveUseCase')
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

it('keeps the use case and the finished step when the sample seeder fails', function (): void {
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
        ->onboarding_step->toBeNull()
        ->and($stage->options()->withoutGlobalScopes()->orderBy('sort_order')->pluck('name')->all())
        ->toBe(array_keys(OnboardingUseCase::Recruiting->stagePreset()));
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
