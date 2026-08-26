<?php

declare(strict_types=1);

use App\Actions\Jetstream\CreateTeam as CreateTeamAction;
use App\Enums\OnboardingReferralSource;
use App\Enums\OnboardingUseCase;
use App\Features\OnboardSeed;
use App\Filament\Pages\CreateTeam;
use App\Filament\Pages\Dashboard;
use App\Models\Team;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Pennant\Feature;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

mutates(CreateTeam::class, CreateTeamAction::class);

// This file is the coverage for demo seeding itself, so it opts back into the
// feature that TestCase switches off for the rest of the suite.
beforeEach(function (): void {
    Feature::define(OnboardSeed::class, true);
});

it('subsequent teams can skip optional referral source', function (): void {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Second Team',
            'slug' => 'second-team',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = $user->fresh()->ownedTeams()->where('name', 'Second Team')->first();

    expect($team)->not->toBeNull()
        ->and($team->onboarding_referral_source)->toBeNull();
});

it('stores referral source', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'onboarding_referral_source' => OnboardingReferralSource::Google->value,
            'name' => 'Referral Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Referral Team')->first();

    expect($team->onboarding_referral_source)->toBe(OnboardingReferralSource::Google);
});

it('stores onboarding context', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led', 'enterprise'],
            'name' => 'Context Team',
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Context Team')->first();

    expect($team->onboarding_context)->toBe(['product_led', 'enterprise']);
});

it('sends team invitations when invite emails are provided', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Invite Test Team',
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'invites' => [
                ['email' => 'alice@example.com', 'role' => 'editor'],
                ['email' => 'bob@example.com', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Invite Test Team')->first();

    expect($team->teamInvitations)->toHaveCount(2)
        ->and($team->teamInvitations->pluck('email')->sort()->values()->all())
        ->toBe(['alice@example.com', 'bob@example.com']);
});

it('sends invitations with correct roles', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Role Test Team',
            'onboarding_use_case' => OnboardingUseCase::Sales->value,
            'onboarding_context' => ['product_led'],
            'invites' => [
                ['email' => 'member@example.com', 'role' => 'editor'],
                ['email' => 'admin@example.com', 'role' => 'admin'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Role Test Team')->first();
    $invitations = $team->teamInvitations->sortBy('email')->values();

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

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Partial Invite Team',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'alice@example.com', 'role' => 'editor'],
                ['email' => '', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Partial Invite Team')->first();

    expect($team->teamInvitations)->toHaveCount(1)
        ->and($team->teamInvitations->first()->email)->toBe('alice@example.com');
});

it('creates team without invitations when no emails are provided', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'No Invite Team',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'No Invite Team')->first();

    expect($team->teamInvitations)->toBeEmpty();
});

it('rejects empty onboarding context for use cases that have sub-options', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(fn () => resolve(CreateTeamAction::class)->create($user, [
        'name' => 'Tampered Team',
        'slug' => 'tampered-team',
        'onboarding_use_case' => OnboardingUseCase::Sales->value,
        'onboarding_context' => [],
    ]))->toThrow(ValidationException::class);

    expect(Team::where('slug', 'tampered-team')->exists())->toBeFalse();
});

it('rejects unknown onboarding context values for the chosen use case', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(fn () => resolve(CreateTeamAction::class)->create($user, [
        'name' => 'Tampered Team',
        'slug' => 'tampered-team',
        'onboarding_use_case' => OnboardingUseCase::Sales->value,
        'onboarding_context' => ['not_a_real_option'],
    ]))->toThrow(ValidationException::class);

    expect(Team::where('slug', 'tampered-team')->exists())->toBeFalse();
});

it('allows empty onboarding context for use cases without sub-options', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user);

    $team = resolve(CreateTeamAction::class)->create($user, [
        'name' => 'Other Use Case',
        'slug' => 'other-use-case',
        'onboarding_use_case' => OnboardingUseCase::Other->value,
    ]);

    expect($team->slug)->toBe('other-use-case')
        ->and($team->onboarding_use_case)->toBe(OnboardingUseCase::Other);
});

it('warns about invites the form accepted but that are not deliverable', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
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
        ->assertNotified(__('filament/pages/teams.create_team.notifications.some_invites_failed.title'));

    $team = Team::query()->where('name', 'Invite Reporting')->firstOrFail();

    expect($team->teamInvitations()->pluck('email')->all())->toBe(['real.person@gmail.com']);
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

    livewire(CreateTeam::class)
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
                ->title(__('filament/pages/teams.create_team.notifications.some_invites_failed.title'))
                ->body(
                    'first.person@gmail.com: '.__('filament/pages/teams.create_team.notifications.some_invites_failed.send_failed')
                    ."\n".'second.person@gmail.com: '.__('filament/pages/teams.create_team.notifications.some_invites_failed.send_skipped')
                )
                ->warning()
        )
        ->assertRedirect(Dashboard::getUrl(['tenant' => $user->fresh()->currentTeam]));

    $team = Team::query()->where('name', 'Mail Transport Down')->firstOrFail();

    // The row is written before the send, so the first invitation survives and the owner
    // can resend it. The second address is never attempted: one dead connection is enough
    // to know the rest would only wait out the same socket timeout. The two addresses
    // therefore need different advice, which is what the notification body asserts above.
    expect($team->teamInvitations()->pluck('email')->all())->toBe(['first.person@gmail.com']);
});

it('does not send invites when the user skips the invite step', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Skipped Invites',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'not.invited@gmail.com', 'role' => 'editor'],
            ],
        ])
        ->call('skipInvites')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Skipped Invites')->firstOrFail();

    expect($team->teamInvitations()->count())->toBe(0);
});

it('still sends invites when the user confirms them', function (): void {
    Mail::fake();

    $user = User::factory()->create();

    $this->actingAs($user);

    livewire(CreateTeam::class)
        ->fillForm([
            'name' => 'Confirmed Invites',
            'onboarding_use_case' => OnboardingUseCase::Other->value,
            'invites' => [
                ['email' => 'really.invited@gmail.com', 'role' => 'editor'],
            ],
        ])
        ->call('register')
        ->assertHasNoFormErrors();

    $team = Team::query()->where('name', 'Confirmed Invites')->firstOrFail();

    expect($team->teamInvitations()->pluck('email')->all())->toBe(['really.invited@gmail.com']);
});
