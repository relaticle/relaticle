<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Actions\EnsureTeamForwardingAddressAction;
use Relaticle\EmailIntegration\Actions\ResolveForwardingSenderAction;
use Relaticle\EmailIntegration\Actions\StoreInboundEmailAction;
use Relaticle\EmailIntegration\Enums\EmailBlocklistType;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Filament\Pages\ForwardingAddressSettingsPage;
use Relaticle\EmailIntegration\Jobs\ProcessInboundEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\TeamForwardingAddress;
use Relaticle\EmailIntegration\Models\UserForwardingBlocklist;
use Relaticle\EmailIntegration\Models\UserForwardingFullAccessGrant;
use Relaticle\EmailIntegration\Models\UserForwardingSettings;
use Relaticle\EmailIntegration\Services\PostmarkInboundAuthenticationValidator;
use Relaticle\EmailIntegration\Services\PostmarkInboundParser;

mutates(
    EnsureTeamForwardingAddressAction::class,
    ProcessInboundEmailJob::class,
    EmailAccountsPage::class,
    ForwardingAddressSettingsPage::class,
);

/**
 * @param  array<string, mixed>  $payload
 */
function processInboundPayload(array $payload): void
{
    $path = 'inbound-webhooks/test-'.Str::ulid().'.json';
    Storage::disk('local')->put($path, json_encode($payload, JSON_THROW_ON_ERROR));

    (new ProcessInboundEmailJob($path))->handle(
        resolve(PostmarkInboundParser::class),
        resolve(PostmarkInboundAuthenticationValidator::class),
        resolve(ResolveForwardingSenderAction::class),
        resolve(StoreInboundEmailAction::class),
    );
}

/**
 * @param  array<int, array{Name: string, Value: string}>  $headers
 * @return array<int, array{Name: string, Value: string}>
 */
function forwardingAuthHeaders(string $envelopeFrom, array $headers = []): array
{
    return array_merge($headers, [
        ['Name' => 'Received-SPF', 'Value' => "Pass (sender SPF authorized) identity=mailfrom; envelope-from={$envelopeFrom}"],
        ['Name' => 'X-Spam-Tests', 'Value' => 'DKIM_SIGNED,DKIM_VALID,SPF_PASS'],
    ]);
}

beforeEach(function (): void {
    Storage::fake('local');

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setCurrentPanel(Filament::getPanel('app'));
    Filament::setTenant($this->team);

    config()->set('email-integration.inbound.domain', 'inbound.relaticle.test');
    config()->set('email-integration.inbound.webhook_secret', 'test-secret');
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

    $this->postJson(route('inbound-email.webhook', ['token' => 'test-secret']), ['Subject' => 'Hello'])
        ->assertOk();

    Bus::assertDispatched(ProcessInboundEmailJob::class);
});

it('rejects inbound webhooks with an invalid token', function (): void {
    Bus::fake([ProcessInboundEmailJob::class]);

    $this->postJson(route('inbound-email.webhook', ['token' => 'wrong-secret']), ['Subject' => 'Hello'])
        ->assertForbidden();

    Bus::assertNotDispatched(ProcessInboundEmailJob::class);
});

it('rejects inbound webhooks in production when the secret is unset', function (): void {
    Bus::fake([ProcessInboundEmailJob::class]);
    config()->set('email-integration.inbound.webhook_secret', '');
    $this->app->detectEnvironment(fn (): string => 'production');

    $this->postJson(route('inbound-email.webhook', ['token' => 'any-token']), ['Subject' => 'Hello'])
        ->assertForbidden();

    Bus::assertNotDispatched(ProcessInboundEmailJob::class);
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
        'Headers' => forwardingAuthHeaders($this->user->email, [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-1@example.com>'],
        ]),
    ];

    processInboundPayload($payload);

    $email = Email::query()->where('user_id', $this->user->id)->first();

    expect($email)->not->toBeNull()
        ->and($email->connected_account_id)->toBeNull()
        ->and($email->creation_source)->toBe(EmailCreationSource::BCC_INBOUND)
        ->and($email->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT)
        ->and($email->subject)->toBe('Forwarded deal thread');
});

it('stores forwarded mail for a sender matched by connected account email', function (): void {
    $address = TeamForwardingAddress::factory()->create([
        'team_id' => $this->team->id,
        'local_part' => 'acme',
    ]);

    $connectedEmail = 'sales@workspace.example';

    ConnectedAccount::factory()->create([
        'user_id' => $this->user->id,
        'team_id' => $this->team->id,
        'email_address' => $connectedEmail,
    ]);

    $payload = [
        'From' => $connectedEmail,
        'FromFull' => ['Email' => $connectedEmail, 'Name' => 'Sales'],
        'To' => "{$address->local_part}@inbound.relaticle.test",
        'ToFull' => [['Email' => "{$address->local_part}@inbound.relaticle.test", 'Name' => '']],
        'Subject' => 'Connected mailbox forward',
        'TextBody' => 'Body copy',
        'Headers' => forwardingAuthHeaders($connectedEmail, [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-connected@example.com>'],
        ]),
    ];

    processInboundPayload($payload);

    expect(Email::query()->where('user_id', $this->user->id)->value('subject'))
        ->toBe('Connected mailbox forward');
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
        'Headers' => forwardingAuthHeaders('stranger@example.com', [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-2@example.com>'],
        ]),
    ];

    processInboundPayload($payload);

    expect(Email::query()->count())->toBe(0);
});

it('rejects forwarded mail that fails sender authentication', function (): void {
    TeamForwardingAddress::factory()->create([
        'team_id' => $this->team->id,
        'local_part' => 'acme',
    ]);

    $payload = [
        'From' => $this->user->email,
        'FromFull' => ['Email' => $this->user->email, 'Name' => $this->user->name],
        'To' => 'acme@inbound.relaticle.test',
        'ToFull' => [['Email' => 'acme@inbound.relaticle.test', 'Name' => '']],
        'Subject' => 'Spoofed forward',
        'TextBody' => 'Body',
        'Headers' => [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-spoof@example.com>'],
            ['Name' => 'Received-SPF', 'Value' => 'Fail (sender SPF unauthorized) identity=mailfrom; envelope-from=attacker@evil.example'],
        ],
    ];

    processInboundPayload($payload);

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
        'Headers' => forwardingAuthHeaders($this->user->email, [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-3@example.com>'],
        ]),
        'CcFull' => [['Email' => 'blocked@contact.com', 'Name' => 'Blocked']],
    ];

    processInboundPayload($payload);

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
        'Headers' => forwardingAuthHeaders($this->user->email, [
            ['Name' => 'Message-ID', 'Value' => '<forwarded-4@example.com>'],
        ]),
    ];

    processInboundPayload($payload);

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
