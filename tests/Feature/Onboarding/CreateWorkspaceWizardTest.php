<?php

declare(strict_types=1);

use App\Actions\Billing\StartProTrial;
use App\Actions\Jetstream\CreateWorkspace as CreateWorkspaceAction;
use App\Actions\User\UpdateUserName;
use App\Enums\OnboardingReferralSource;
use App\Enums\Plan;
use App\Enums\WorkspaceRole;
use App\Features\Billing as BillingFeature;
use App\Features\SetupConversation;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\SetupWorkspace;
use App\Models\User;
use App\Models\Workspace;
use Filament\Schemas\Components\Wizard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(CreateWorkspace::class, CreateWorkspaceAction::class, StartProTrial::class, UpdateUserName::class);

it('renders the create workspace page with wizard for workspaceless users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertSee('Create your workspace');
});

it('shows a back affordance but no step counter in the wizard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/workspaces.create_workspace.actions.back'))
        ->assertDontSeeHtml('role="progressbar"');
});

it('flags the workspace created event when the wizard finishes', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Tracked Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(session()->get('fathom.track_workspace_created'))->toBeTrue();
});

/**
 * A second workspace is expansion, not acquisition. Its Fathom referrer is
 * whatever brought the user back that day, so crediting a channel with it
 * would be wrong, and counting it alongside first workspaces would push the
 * signup-to-workspace rate past 100%.
 */
it('does not flag the workspace created event for an additional workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Second Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Workspace::query()->where('name', 'Second Corp')->exists())->toBeTrue()
        ->and(session()->has('fathom.track_workspace_created'))->toBeFalse();
});

it('resolves every wizard heading from translations', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.workspace'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.attribution'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.headings.attribution_description'))
        ->assertDontSee('filament/pages/workspaces.create_workspace.headings')
        ->assertDontSee('Workspace heading')
        ->assertDontSee('Attribution heading')
        ->assertDontSee('Onboarding referral source');
});

it('resolves every wizard form label from translations', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/workspaces.create_workspace.form.company_logo.label'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.form.your_name.label'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.form.workspace_name.label'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.form.workspace_handle.label'))
        ->assertDontSee('filament/pages/workspaces.create_workspace.form');
});

it('stores the optional company logo picked during onboarding', function (): void {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'logo' => UploadedFile::fake()->image('logo.png', 200, 200),
            'name' => 'Logo Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Logo Corp')->sole();

    expect($workspace->getFirstMedia(Workspace::LOGO_MEDIA_COLLECTION))->not->toBeNull();
});

it('creates the workspace when no company logo is picked', function (): void {
    Storage::fake('public');

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Logoless Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Workspace::query()->where('name', 'Logoless Corp')->sole()->getMedia(Workspace::LOGO_MEDIA_COLLECTION))
        ->toHaveCount(0);
});

it('prefills the workspace step with the current user name', function (): void {
    $user = User::factory()->create(['name' => 'Ada Lovelace']);

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertFormSet(['user_name' => 'Ada Lovelace']);
});

it('hides your name for a user who already has a workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertFormFieldHidden('user_name');
});

it('hides your name for an invited member who owns no workspace yet', function (): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $member = User::factory()->create();
    $owner->currentWorkspace->users()->attach($member, ['role' => WorkspaceRole::Member->value]);
    $member->forceFill(['current_workspace_id' => $owner->currentWorkspace->getKey()])->save();

    $this->actingAs($member);

    livewire(CreateWorkspace::class)
        ->assertFormFieldHidden('user_name');
});

it('shows your name for a first-run user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertFormFieldVisible('user_name');
});

it('starts the workspace step empty for the user to name it themselves', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertFormSet([
            'name' => null,
            'slug' => null,
        ]);
});

it('derives the handle from the name as it is typed', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Corp'])
        ->assertFormSet(['slug' => 'acme-corp']);
});

it('leaves a handle the user typed alone when the name changes afterwards', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['slug' => 'my-own-handle'])
        ->fillForm(['name' => 'Contoso Industries'])
        ->assertFormSet(['slug' => 'my-own-handle']);
});

it('saves the handle the user typed rather than the one derived from the name', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['slug' => 'my-own-handle'])
        ->fillForm([
            'name' => 'Contoso Industries',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect($user->fresh()->personalWorkspace()->slug)->toBe('my-own-handle');
});

it('picks the lowest free suffix, skipping the ones already in use', function (): void {
    $other = User::factory()->create();
    Workspace::factory()->create(['slug' => 'acme-corp', 'user_id' => $other->id]);
    Workspace::factory()->create(['slug' => 'acme-corp-2', 'user_id' => $other->id]);
    Workspace::factory()->create(['slug' => 'acme-corp-10', 'user_id' => $other->id]);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Corp'])
        ->assertFormSet(['slug' => 'acme-corp-3']);
});

it('creates both workspaces when two signups type the same name at once', function (): void {
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first);
    $firstWizard = livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Corp'])
        ->assertFormSet(['slug' => 'acme-corp']);

    $this->actingAs($second);
    $secondWizard = livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Corp'])
        ->assertFormSet(['slug' => 'acme-corp']);

    $this->actingAs($first);
    $firstWizard
        ->call('register')
        ->assertHasNoFormErrors();

    $this->actingAs($second);
    $secondWizard
        ->call('register')
        ->assertHasNoFormErrors();

    expect(Workspace::query()->whereIn('user_id', [$first->id, $second->id])->pluck('slug')->sort()->values()->all())
        ->toBe(['acme-corp', 'acme-corp-2']);
});

it('saves the handle it previewed for a name that transliterates to nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $wizard = livewire(CreateWorkspace::class)->fillForm(['name' => '株式会社テスト']);
    $previewed = $wizard->get('data')['slug'];

    $wizard
        ->call('register')
        ->assertHasNoFormErrors();

    expect($user->fresh()->personalWorkspace()->slug)->toBe($previewed);
});

it('previews a handle that clears the reserved route segments, the way the save does', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Billing'])
        ->assertFormSet(['slug' => 'billing-2']);
});

it('still rejects a handle the user typed themselves when it is taken', function (): void {
    $other = User::factory()->create();
    Workspace::factory()->create(['slug' => 'acme-corp', 'user_id' => $other->id]);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'slug' => 'acme-corp',
        ])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('suffixes a typed name that slugs to a handle already in use', function (): void {
    $other = User::factory()->create();
    Workspace::factory()->create(['slug' => 'acme-corp', 'user_id' => $other->id]);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Corp'])
        ->assertFormSet(['slug' => 'acme-corp-2']);
});

it('ignores a taken handle whose suffix is too long to be a number', function (): void {
    $other = User::factory()->create();
    Workspace::factory()->create(['slug' => 'globex', 'user_id' => $other->id]);
    Workspace::factory()->create(['slug' => 'globex-99999999999', 'user_id' => $other->id]);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Globex'])
        ->assertFormSet(['slug' => 'globex-2']);
});

it('requires both a workspace name and a handle when the user fills neither', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->call('register')
        ->assertHasFormErrors(['name' => 'required', 'slug' => 'required']);

    expect($user->fresh()->personalWorkspace())->toBeNull();
});

it('renders wizard for users who already have a workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertSee('Create your workspace');
});

it('offers a way back to the current workspace for users who already have one', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    $component = livewire(CreateWorkspace::class);

    expect($component->instance()->getCancelUrl())
        ->toBe(Dashboard::getUrl(['tenant' => $user->currentWorkspace]));

    $component->assertSee('workspace-cancel-link');
});

it('offers no way back for workspaceless users, who have nowhere to go', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateWorkspace::class);

    expect($component->instance()->getCancelUrl())->toBeNull();

    $component->assertDontSee('workspace-cancel-link');
});

it('creates a workspace with a derived handle and no use case yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Acme Corp'])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Acme Corp')->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->slug)->toBe('acme-corp')
        ->and($workspace->onboarding_use_case)->toBeNull();
});

it('subsequent workspaces can skip optional referral source', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Second Workspace',
            'slug' => 'second-workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->ownedWorkspaces()->where('name', 'Second Workspace')->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->onboarding_referral_source)->toBeNull();
});

it('stores referral source', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_referral_source' => OnboardingReferralSource::Google->value,
            'name' => 'Referral Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Referral Workspace')->first();

    expect($workspace->onboarding_referral_source)->toBe(OnboardingReferralSource::Google);
});

it('stores the assistant and the question behind an AI referral', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_referral_source' => OnboardingReferralSource::AI->value,
            'onboarding_referral_detail' => 'claude',
            'onboarding_referral_prompt' => 'A CRM my assistant can update',
            'name' => 'Assistant Sent Me',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Assistant Sent Me')->sole();

    expect($workspace->onboarding_referral_source)->toBe(OnboardingReferralSource::AI)
        ->and($workspace->onboarding_referral_detail)->toBe('claude')
        ->and($workspace->onboarding_referral_prompt)->toBe('A CRM my assistant can update');
});

it('stores an AI referral that answers neither follow-up', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_referral_source' => OnboardingReferralSource::AI->value,
            'name' => 'Quiet Referral',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Quiet Referral')->sole();

    expect($workspace->onboarding_referral_source)->toBe(OnboardingReferralSource::AI)
        ->and($workspace->onboarding_referral_detail)->toBeNull()
        ->and($workspace->onboarding_referral_prompt)->toBeNull();
});

it('asks which assistant and what was asked only for an AI referral', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->goToWizardStep(2)
        ->assertWizardCurrentStep(2)
        ->fillForm([
            'onboarding_referral_source' => OnboardingReferralSource::GitHub->value,
        ])
        ->assertFormFieldHidden('onboarding-attribution.onboarding_referral_detail')
        ->assertFormFieldHidden('onboarding-attribution.onboarding_referral_prompt')
        ->fillForm([
            'onboarding_referral_source' => OnboardingReferralSource::AI->value,
        ])
        ->assertFormFieldVisible('onboarding-attribution.onboarding_referral_detail')
        ->assertFormFieldVisible('onboarding-attribution.onboarding_referral_prompt')
        ->assertSee(__('filament/pages/workspaces.create_workspace.form.referral_detail_label'))
        ->assertSee(__('filament/pages/workspaces.create_workspace.form.referral_prompt_label'))
        ->assertSee('Perplexity');
});

it('shows the AI follow-ups as soon as the source is picked, without waiting for Continue', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->goToWizardStep(2)
        ->assertSeeHtml('wire:model.live="data.onboarding_referral_source"');
});

it('caps the question behind an AI referral at 200 characters', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'onboarding_referral_source' => OnboardingReferralSource::AI->value,
            'onboarding_referral_prompt' => str_repeat('a', 201),
            'name' => 'Long Question Co',
        ])
        ->call('register')
        ->assertHasFormErrors(['onboarding_referral_prompt' => 'max']);
});

it('the action drops the assistant and the question for a source that asks for neither', function (): void {
    $user = User::factory()->create();

    $workspace = resolve(CreateWorkspaceAction::class)->create($user, [
        'name' => 'Search Visitor Co',
        'slug' => 'search-visitor-co',
        'onboarding_referral_source' => OnboardingReferralSource::Google->value,
        'onboarding_referral_detail' => 'claude',
        'onboarding_referral_prompt' => 'A CRM my assistant can update',
    ]);

    expect($workspace->onboarding_referral_source)->toBe(OnboardingReferralSource::Google)
        ->and($workspace->onboarding_referral_detail)->toBeNull()
        ->and($workspace->onboarding_referral_prompt)->toBeNull();
});

it('the action rejects an assistant the AI referral does not offer', function (): void {
    $user = User::factory()->create();

    expect(fn (): Workspace => resolve(CreateWorkspaceAction::class)->create($user, [
        'name' => 'Unknown Assistant Co',
        'slug' => 'unknown-assistant-co',
        'onboarding_referral_source' => OnboardingReferralSource::AI->value,
        'onboarding_referral_detail' => 'a-made-up-assistant',
    ]))->toThrow(ValidationException::class);

    expect(Workspace::query()->where('name', 'Unknown Assistant Co')->exists())->toBeFalse();
});

it('the action rejects a question over 200 characters', function (): void {
    $user = User::factory()->create();

    expect(fn (): Workspace => resolve(CreateWorkspaceAction::class)->create($user, [
        'name' => 'Tampered Question Co',
        'slug' => 'tampered-question-co',
        'onboarding_referral_source' => OnboardingReferralSource::AI->value,
        'onboarding_referral_prompt' => str_repeat('a', 201),
    ]))->toThrow(ValidationException::class);

    expect(Workspace::query()->where('name', 'Tampered Question Co')->exists())->toBeFalse();
});

it('has two steps for a first workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertWizardStepExists(2)
        ->assertDontSee('Collaborate with your team')
        ->assertDontSee('Copy invite link');

    $wizard = $component->instance()->form->getComponent(fn (mixed $component): bool => $component instanceof Wizard);

    expect($wizard->getDefaultChildComponents())->toHaveCount(2);
});

it('has one step for a second workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    $component = livewire(CreateWorkspace::class)
        ->assertSuccessful()
        ->assertWizardStepExists(1)
        ->assertDontSee('Collaborate with your team')
        ->assertDontSee('Copy invite link');

    $wizard = $component->instance()->form->getComponent(fn (mixed $component): bool => $component instanceof Wizard);

    expect($wizard->getDefaultChildComponents())->toHaveCount(1);
});

it('hides the account menu links while no workspace is bound, instead of sending them to the dashboard', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->get('/app/new')
        ->assertSuccessful()
        ->assertDontSee(__('filament/panel.user_menu.settings'))
        ->assertDontSee(__('access-tokens.user_menu'));
});

it('shows the account menu links inside a workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    $this->get('/app/'.$user->currentWorkspace->slug)
        ->assertSuccessful()
        ->assertSee(__('filament/panel.user_menu.settings'))
        ->assertSee(__('access-tokens.user_menu'));
});

it('automatically starts one 14-day Cloud Pro trial after hosted onboarding', function (): void {
    Feature::define(BillingFeature::class, true);

    $user = User::factory()->create();
    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Trial Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    /** @var Workspace $workspace */
    $workspace = $user->refresh()->currentWorkspace;
    $balance = AiCreditBalance::query()->where('workspace_id', $workspace->getKey())->sole();

    expect($workspace->plan)->toBe(Plan::Pro)
        ->and($workspace->onGenericTrial())->toBeTrue()
        ->and($workspace->trial_ends_at?->isSameDay(now()->addDays(14)))->toBeTrue()
        ->and($workspace->pro_trial_used_at)->not->toBeNull()
        ->and($balance->credits_remaining)->toBe(Plan::Pro->credits());
});

it('starts a fresh trial for each additional hosted workspace', function (): void {
    Feature::define(BillingFeature::class, true);

    $user = User::factory()->withPersonalWorkspace()->create();
    $user->currentWorkspace?->forceFill(['pro_trial_used_at' => now()])->save();
    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Second Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    /** @var Workspace $workspace */
    $workspace = $user->refresh()->currentWorkspace;

    expect($workspace->plan)->toBe(Plan::Pro)
        ->and($workspace->onGenericTrial())->toBeTrue()
        ->and($workspace->pro_trial_used_at)->not->toBeNull()
        ->and($workspace->hosted_free_grandfathered_at)->toBeNull();
});

it('creates a workspace with a custom slug', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'slug' => 'my-workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Acme Corp')->first();

    expect($workspace)->not->toBeNull()
        ->and($workspace->slug)->toBe('my-workspace');
});

it('validates slug format', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'slug' => 'INVALID SLUG!!',
        ])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('validates slug uniqueness', function (): void {
    $existingUser = User::factory()->create();
    Workspace::factory()->create(['slug' => 'taken-slug', 'user_id' => $existingUser->id]);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'slug' => 'taken-slug',
        ])
        ->call('register')
        ->assertHasFormErrors(['slug']);
});

it('requires a workspace name', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('register')
        ->assertHasFormErrors(['name']);
});

it('updates the user name when corrected during onboarding', function (): void {
    $user = User::factory()->create(['name' => 'Guessed Name']);

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'user_name' => 'Corrected Name',
            'name' => 'Acme Corp',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    expect($user->fresh()->name)->toBe('Corrected Name');
});

it('requires your name', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Acme Corp',
            'user_name' => '',
        ])
        ->call('register')
        ->assertHasFormErrors(['user_name']);

    expect(Workspace::query()->where('name', 'Acme Corp')->exists())->toBeFalse();
});

it('marks first workspace as personal workspace', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'My First Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = $user->fresh()->ownedWorkspaces->first();

    expect($workspace->personal_workspace)->toBeTrue();
});

it('marks subsequent workspaces as non-personal', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Second Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $secondWorkspace = $user->fresh()->ownedWorkspaces()->where('name', 'Second Workspace')->first();

    expect($secondWorkspace->personal_workspace)->toBeFalse();
});

it('redirects a first workspace to its setup page', function (): void {
    Feature::define(SetupConversation::class, true);

    $user = User::factory()->create();

    $this->actingAs($user);

    $component = livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Redirect Workspace'])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertNotNotified();

    $component->assertRedirect(SetupWorkspace::getUrl(['tenant' => $user->fresh()->currentWorkspace]));
});

it('redirects a first workspace to its setup page when the setup conversation is off', function (): void {
    Feature::define(SetupConversation::class, false);

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Redirect Workspace'])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(SetupWorkspace::getUrl(['tenant' => $user->fresh()->currentWorkspace]));
});

it('redirects subsequent workspaces to their setup page', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm(['name' => 'Second Workspace'])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertRedirect(SetupWorkspace::getUrl(['tenant' => $user->fresh()->currentWorkspace]));
});
