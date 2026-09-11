<?php

declare(strict_types=1);

use App\Actions\Jetstream\CreateWorkspace as CreateWorkspaceAction;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Features\OnboardSeed;
use App\Filament\Pages\CreateWorkspace;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use App\Models\Workspace;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Laravel\Pennant\Feature;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

mutates(CreateWorkspace::class, CreateWorkspaceAction::class);

// This file is the coverage for demo seeding itself, so it opts back into the
// feature that TestCase switches off for the rest of the suite.
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});

it('subsequent workspaces can skip optional referral source', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Second Workspace',
            'slug' => 'second-workspace',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
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
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_referral_source' => OnboardingReferralSource::Google->value,
            'name' => 'Referral Workspace',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Referral Workspace')->first();

    expect($workspace->onboarding_referral_source)->toBe(OnboardingReferralSource::Google);
});

it('sends workspace invitations when invite emails are provided', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Invite Test Workspace',
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'invites' => [
                ['email' => 'alice@example.com', 'role' => 'editor'],
                ['email' => 'bob@example.com', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Invite Test Workspace')->first();

    expect($workspace->workspaceInvitations)->toHaveCount(2)
        ->and($workspace->workspaceInvitations->pluck('email')->sort()->values()->all())
        ->toBe(['alice@example.com', 'bob@example.com']);
});

it('sends invitations with correct roles', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Role Test Workspace',
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'invites' => [
                ['email' => 'member@example.com', 'role' => 'editor'],
                ['email' => 'admin@example.com', 'role' => 'admin'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Role Test Workspace')->first();
    $invitations = $workspace->workspaceInvitations->sortBy('email')->values();

    expect($invitations)->toHaveCount(2)
        ->and($invitations[0]->email)->toBe('admin@example.com')
        ->and($invitations[0]->role)->toBe('admin')
        ->and($invitations[1]->email)->toBe('member@example.com')
        ->and($invitations[1]->role)->toBe('editor');
});

it('sends only valid invitations when some emails are empty', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Partial Invite Workspace',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'alice@example.com', 'role' => 'editor'],
                ['email' => '', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Partial Invite Workspace')->first();

    expect($workspace->workspaceInvitations)->toHaveCount(1)
        ->and($workspace->workspaceInvitations->first()->email)->toBe('alice@example.com');
});

it('creates workspace without invitations when no emails are provided', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'No Invite Workspace',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'No Invite Workspace')->first();

    expect($workspace->workspaceInvitations)->toBeEmpty();
});

it('warns about invites the form accepted but that are not deliverable', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Invite Reporting',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'user@example', 'role' => 'editor'],
                ['email' => 'real.person@gmail.com', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertNotified(__('filament/pages/workspaces.create_workspace.notifications.some_invites_failed.title'));

    $workspace = Workspace::query()->where('name', 'Invite Reporting')->firstOrFail();

    expect($workspace->workspaceInvitations()->pluck('email')->all())->toBe(['real.person@gmail.com']);
});

it('finishes onboarding when the mail transport is down', function (): void {
    config()->set('mail.mailers.failing', ['transport' => 'failing']);
    config()->set('mail.default', 'failing');

    Mail::extend('failing', fn (array $config): TransportInterface => new class implements TransportInterface
    {
        public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
        {
            throw new TransportException('Connection could not be established.');
        }

        public function __toString(): string
        {
            return 'failing';
        }
    });

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Mail Transport Down',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'first.person@gmail.com', 'role' => 'editor'],
                ['email' => 'second.person@gmail.com', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors()
        ->assertNotified(
            Notification::make()
                ->title(__('filament/pages/workspaces.create_workspace.notifications.some_invites_failed.title'))
                ->body(
                    'first.person@gmail.com: '.__('filament/pages/workspaces.create_workspace.notifications.some_invites_failed.send_failed')
                    ."\n".'second.person@gmail.com: '.__('filament/pages/workspaces.create_workspace.notifications.some_invites_failed.send_skipped')
                )
                ->warning()
        )
        ->assertRedirect(Dashboard::getUrl(['tenant' => $user->fresh()->currentWorkspace]));

    $workspace = Workspace::query()->where('name', 'Mail Transport Down')->firstOrFail();

    // The row is written before the send, so the first invitation survives and the owner
    // can resend it. The second address is never attempted: one dead connection is enough
    // to know the rest would only wait out the same socket timeout. The two addresses
    // therefore need different advice, which is what the notification body asserts above.
    expect($workspace->workspaceInvitations()->pluck('email')->all())->toBe(['first.person@gmail.com']);
});

it('does not send invites when the user skips the invite step', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Skipped Invites',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'not.invited@gmail.com', 'role' => 'editor'],
            ],
        ])
        ->call('skipInvites')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Skipped Invites')->firstOrFail();

    expect($workspace->workspaceInvitations()->count())->toBe(0);
});

it('still sends invites when the user confirms them', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateWorkspace::class)
        ->fillForm([
            'name' => 'Confirmed Invites',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'really.invited@gmail.com', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $workspace = Workspace::query()->where('name', 'Confirmed Invites')->firstOrFail();

    expect($workspace->workspaceInvitations()->pluck('email')->all())->toBe(['really.invited@gmail.com']);
});
