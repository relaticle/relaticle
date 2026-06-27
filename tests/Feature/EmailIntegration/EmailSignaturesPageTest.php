<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Filament\Pages\EmailSignaturesPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;
use Relaticle\EmailIntegration\Services\HtmlSanitizerService;

mutates(EmailSignaturesPage::class);
mutates(HtmlSanitizerService::class);

function foreignAccount(string $accountId, string $email): ConnectedAccount
{
    $otherUser = User::factory()->withTeam()->create();

    return ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::create([
        'team_id' => $otherUser->currentTeam->id,
        'user_id' => $otherUser->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => $accountId,
        'email_address' => $email,
        'display_name' => 'Other Sender',
        'access_token' => 'fake-token',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));
}

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'test-account-id',
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
        'access_token' => 'fake-token',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));
});

// ── createSignature ───────────────────────────────────────────────────────────

it('creates a signature and sends success notification', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => $this->account->id,
            'name' => 'Work Signature',
            'content_html' => '<p>Best regards</p>',
            'is_default' => false,
        ])
        ->assertNotified('Signature created.');

    expect(EmailSignature::where('name', 'Work Signature')->exists())->toBeTrue();
});

it('preselects the connected account when opening the create form', function (): void {
    livewire(EmailSignaturesPage::class)
        ->mountAction('createSignature')
        ->assertSet('mountedActions.0.data.connected_account_id', $this->account->id);
});

it('preselects the default account, not merely the oldest, when opening the create form', function (): void {
    // $this->account is the oldest. Add a newer account and mark it default.
    $newer = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'default-account-id',
        'email_address' => 'default@example.com',
        'display_name' => 'Default Sender',
        'is_default' => true,
        'access_token' => 'fake-token-default',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));

    livewire(EmailSignaturesPage::class)
        ->mountAction('createSignature')
        ->assertSet('mountedActions.0.data.connected_account_id', $newer->id);
});

it('requires connected_account_id when creating a signature', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => null,
            'name' => 'Work Signature',
            'content_html' => '<p>Best regards</p>',
        ])
        ->assertHasActionErrors(['connected_account_id' => 'required']);
});

it('requires name when creating a signature', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => $this->account->id,
            'name' => null,
            'content_html' => '<p>Best regards</p>',
        ])
        ->assertHasActionErrors(['name' => 'required']);
});

it('requires content_html when creating a signature', function (): void {
    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => $this->account->id,
            'name' => 'Work Signature',
            'content_html' => [
                'type' => 'doc',
                'content' => [
                    ['type' => 'paragraph'],
                ],
            ],
        ])
        ->assertHasActionErrors(['content_html']);
});

it('rejects creating a signature for an account the user does not own', function (): void {
    $foreignAccount = foreignAccount('foreign-create-account', 'foreign-create@example.com');

    livewire(EmailSignaturesPage::class)
        ->callAction('createSignature', data: [
            'connected_account_id' => $foreignAccount->id,
            'name' => 'Hijacked Signature',
            'content_html' => '<p>nope</p>',
            'is_default' => false,
        ])
        ->assertNotNotified('Signature created.');

    expect(EmailSignature::where('connected_account_id', $foreignAccount->id)->exists())->toBeFalse();
});

// ── editSignature ─────────────────────────────────────────────────────────────

it('updates name and content and sends success notification', function (): void {
    $signature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Original',
        'content_html' => '<p>Old</p>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('editSignature', arguments: ['signature_id' => $signature->id], data: [
            'name' => 'Updated',
            'content_html' => '<p>New content</p>',
            'is_default' => false,
        ])
        ->assertNotified('Signature updated.');

    expect($signature->fresh())
        ->name->toBe('Updated')
        ->content_html->toBe('<p>New content</p>');
});

it('clears previous default when is_default is toggled on', function (): void {
    $previousDefault = EmailSignature::factory()->default()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Old Default',
        'content_html' => '<p>Old</p>',
    ]);

    $other = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Other',
        'content_html' => '<p>Other</p>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('editSignature', arguments: ['signature_id' => $other->id], data: [
            'name' => 'Other',
            'content_html' => '<p>Other</p>',
            'is_default' => true,
        ])
        ->assertNotified('Signature updated.');

    expect($other->fresh()->is_default)->toBeTrue()
        ->and($previousDefault->fresh()->is_default)->toBeFalse();
});

it('does not update another user\'s signature', function (): void {
    $foreignAccount = foreignAccount('foreign-edit-account', 'foreign-edit@example.com');

    $otherSignature = EmailSignature::factory()->create([
        'connected_account_id' => $foreignAccount->id,
        'user_id' => $foreignAccount->user_id,
        'name' => 'Theirs',
        'content_html' => '<p>Original</p>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('editSignature', arguments: ['signature_id' => $otherSignature->id], data: [
            'name' => 'Hijacked',
            'content_html' => '<p><img src=x onerror=alert(1)></p>',
            'is_default' => false,
        ])
        ->assertNotNotified('Signature updated.');

    expect($otherSignature->fresh())
        ->name->toBe('Theirs')
        ->content_html->toBe('<p>Original</p>');
});

// ── deleteSignature ───────────────────────────────────────────────────────────

it('deletes the signature and sends success notification', function (): void {
    $signature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'To Delete',
        'content_html' => '<p>Bye</p>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('deleteSignature', arguments: ['signature_id' => $signature->id])
        ->assertNotified('Signature deleted.');

    expect(EmailSignature::whereKey($signature->id)->exists())->toBeFalse();
});

it('does not delete another user\'s signature', function (): void {
    $otherUser = User::factory()->withTeam()->create();

    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'other-account-id',
        'email_address' => 'other@example.com',
        'display_name' => 'Other Sender',
        'access_token' => 'fake-token-2',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));

    $otherSignature = EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
        'name' => 'Other Signature',
        'content_html' => '<p>Other</p>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('deleteSignature', arguments: ['signature_id' => $otherSignature->id])
        ->assertNotNotified('Signature deleted.');

    expect(EmailSignature::whereKey($otherSignature->id)->exists())->toBeTrue();
});

// ── mount / page scope ────────────────────────────────────────────────────────

it('shows only the authenticated user\'s signatures on mount', function (): void {
    $mySignature = EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'My Signature',
        'content_html' => '<p>Mine</p>',
    ]);

    $otherUser = User::factory()->withTeam()->create();

    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'other-account-2',
        'email_address' => 'other2@example.com',
        'display_name' => 'Other',
        'access_token' => 'fake-token-3',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));

    $otherSignature = EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
        'name' => 'Other Signature',
        'content_html' => '<p>Theirs</p>',
    ]);

    $component = livewire(EmailSignaturesPage::class);

    $ids = $component->get('signatures')->pluck('id')->all();

    expect($ids)->toContain($mySignature->id)
        ->and($ids)->not->toContain($otherSignature->id);
});

it('excludes another user\'s signatures even when in the same team', function (): void {
    $otherUser = User::factory()->create();
    $this->team->users()->attach($otherUser);

    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::create([
        'team_id' => $this->team->id,
        'user_id' => $otherUser->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'teammate-account',
        'email_address' => 'teammate@example.com',
        'display_name' => 'Teammate',
        'access_token' => 'fake-token-4',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]));

    $teammateSignature = EmailSignature::factory()->create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
        'name' => 'Teammate Signature',
        'content_html' => '<p>Teammate</p>',
    ]);

    $component = livewire(EmailSignaturesPage::class);

    $ids = $component->get('signatures')->pluck('id')->all();

    expect($ids)->not->toContain($teammateSignature->id);
});

// ── render sanitization ───────────────────────────────────────────────────────

it('sanitizes signature html when rendering the page', function (): void {
    EmailSignature::factory()->create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'XSS Signature',
        'content_html' => '<b>hi</b><script>alert(document.cookie)</script><img src=x onerror=alert(1)>',
    ]);

    livewire(EmailSignaturesPage::class)
        ->assertDontSee('<script>', escape: false)
        ->assertDontSee('onerror', escape: false)
        ->assertSee('hi');
});
