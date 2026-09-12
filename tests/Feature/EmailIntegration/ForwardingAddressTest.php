<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Actions\EnsureTeamForwardingAddressAction;
use Relaticle\EmailIntegration\Actions\ResolveForwardingSenderAction;
use Relaticle\EmailIntegration\Actions\StoreInboundEmailAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\ForwardingAddressSettingsPage;
use Relaticle\EmailIntegration\Jobs\ProcessInboundEmailJob;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;
use Relaticle\EmailIntegration\Services\PostmarkInboundParser;

mutates(
    EnsureTeamForwardingAddressAction::class,
    ProcessInboundEmailJob::class,
    EmailAccountsPage::class,
    ForwardingAddressSettingsPage::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team);

    config()->set('email-integration.inbound.domain', 'inbound.relaticle.test');
});

it('creates a stable team forwarding address from the workspace slug', function (): void {
    $address = resolve(EnsureTeamForwardingAddressAction::class)->execute($this->team);

    expect($address->local_part)->toBe($this->team->slug)
        ->and($address->fullAddress())->toBe("{$this->team->slug}@inbound.relaticle.test");
});

it('shows the forwarding address on the accounts page', function (): void {
    livewire(EmailAccountsPage::class)
        ->assertSee("{$this->team->slug}@inbound.relaticle.test")
        ->assertSee(__('filament/pages/email-accounts.in_sync'));
});

it('dispatches inbound email processing from the webhook', function (): void {
    Bus::fake([ProcessInboundEmailJob::class]);

    $this->postJson(route('inbound-email.webhook'), ['Subject' => 'Hello'])
        ->assertOk();

    Bus::assertDispatched(ProcessInboundEmailJob::class);
});

it('stores forwarded mail for a verified workspace sender', function (): void {
    $address = TeamForwardingAddress::factory()->create([
        'team_id' => $this->team->id,
        'local_part' => 'acme',
    ]);

    UserForwardingSettings::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'sharing_tier' => EmailPrivacyTier::SUBJECT,
    ]);

    $payload = [
        'From' => $this->user->email,
        'FromFull' => ['Email' => $this->user->email, 'Name' => $this->user->name],
        'To' => "{$address->local_part}@inbound.relaticle.test",
        'ToFull' => [['Email' => "{$address->local_part}@inbound.relaticle.test", 'Name' => '']],
        'Subject' => 'Forwarded deal thread',
        'TextBody' => 'Body copy',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-1@example.com>'],
        ],
    ];

    (new ProcessInboundEmailJob($payload))->handle(
        resolve(PostmarkInboundParser::class),
        resolve(ResolveForwardingSenderAction::class),
        resolve(StoreInboundEmailAction::class),
    );

    $email = Email::query()->where('user_id', $this->user->id)->first();

    expect($email)->not->toBeNull()
        ->and($email->connected_account_id)->toBeNull()
        ->and($email->creation_source)->toBe(EmailCreationSource::BCC_INBOUND)
        ->and($email->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT)
        ->and($email->subject)->toBe('Forwarded deal thread');
});

it('rejects forwarded mail from an unknown sender', function (): void {
    TeamForwardingAddress::factory()->create([
        'team_id' => $this->team->id,
        'local_part' => 'acme',
    ]);

    $payload = [
        'From' => 'stranger@example.com',
        'FromFull' => ['Email' => 'stranger@example.com', 'Name' => 'Stranger'],
        'To' => 'acme@inbound.relaticle.test',
        'ToFull' => [['Email' => 'acme@inbound.relaticle.test', 'Name' => '']],
        'Subject' => 'Should not store',
        'TextBody' => 'Body',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-2@example.com>'],
        ],
    ];

    (new ProcessInboundEmailJob($payload))->handle(
        resolve(PostmarkInboundParser::class),
        resolve(ResolveForwardingSenderAction::class),
        resolve(StoreInboundEmailAction::class),
    );

    expect(Email::query()->count())->toBe(0);
});

it('skips storing mail that matches the forwarding blocklist', function (): void {
    $address = TeamForwardingAddress::factory()->create([
        'team_id' => $this->team->id,
        'local_part' => 'acme',
    ]);

    UserForwardingBlocklist::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'type' => EmailBlocklistType::EMAIL,
        'value' => 'blocked@contact.com',
    ]);

    $payload = [
        'From' => $this->user->email,
        'FromFull' => ['Email' => $this->user->email, 'Name' => $this->user->name],
        'To' => "{$address->local_part}@inbound.relaticle.test",
        'ToFull' => [['Email' => "{$address->local_part}@inbound.relaticle.test", 'Name' => '']],
        'Subject' => 'Blocked participant',
        'TextBody' => 'Body',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-3@example.com>'],
        ],
        'CcFull' => [['Email' => 'blocked@contact.com', 'Name' => 'Blocked']],
    ];

    (new ProcessInboundEmailJob($payload))->handle(
        resolve(PostmarkInboundParser::class),
        resolve(ResolveForwardingSenderAction::class),
        resolve(StoreInboundEmailAction::class),
    );

    expect(Email::query()->count())->toBe(0);
});

it('seeds full access shares for granted teammates', function (): void {
    $teammate = User::factory()->create();
    $this->team->users()->attach($teammate, ['role' => 'member']);

    TeamForwardingAddress::factory()->create([
        'team_id' => $this->team->id,
        'local_part' => 'acme',
    ]);

    UserForwardingFullAccessGrant::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'granted_user_id' => $teammate->id,
    ]);

    $payload = [
        'From' => $this->user->email,
        'FromFull' => ['Email' => $this->user->email, 'Name' => $this->user->name],
        'To' => 'acme@inbound.relaticle.test',
        'ToFull' => [['Email' => 'acme@inbound.relaticle.test', 'Name' => '']],
        'Subject' => 'Shared forward',
        'TextBody' => 'Body',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-4@example.com>'],
        ],
    ];

    (new ProcessInboundEmailJob($payload))->handle(
        resolve(PostmarkInboundParser::class),
        resolve(ResolveForwardingSenderAction::class),
        resolve(StoreInboundEmailAction::class),
    );

    $email = Email::query()->first();

    expect($email)->not->toBeNull();

    $this->assertDatabaseHas(EmailShare::class, [
        'email_id' => $email->id,
        'shared_with' => $teammate->id,
        'tier' => EmailPrivacyTier::FULL->value,
    ]);
});

it('opens the forwarding settings page without a pre-existing address row', function (): void {
    livewire(ForwardingAddressSettingsPage::class)
        ->assertSuccessful()
        ->assertSee(__('filament/pages/forwarding-address-settings.tabs.general'))
        ->assertSee(__('filament/pages/forwarding-address-settings.tabs.blocklist'));

    $this->assertDatabaseHas(TeamForwardingAddress::class, [
        'team_id' => $this->team->id,
    ]);
});

it('loads the forwarding settings page over http', function (): void {
    $this->get(ForwardingAddressSettingsPage::getUrl())
        ->assertOk()
        ->assertSee(__('filament/pages/forwarding-address-settings.visibility.label'));
});

it('saves forwarding visibility settings from the settings page', function (): void {
    livewire(ForwardingAddressSettingsPage::class)
        ->fillForm(['sharing_tier' => EmailPrivacyTier::FULL->value])
        ->callAction('save')
        ->assertNotified();

    $this->assertDatabaseHas(UserForwardingSettings::class, [
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'sharing_tier' => EmailPrivacyTier::FULL->value,
    ]);
});
