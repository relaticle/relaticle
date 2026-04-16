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

mutates(EmailSignaturesPage::class);

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

// ── editSignature ─────────────────────────────────────────────────────────────

it('updates name and content and sends success notification', function (): void {
    $signature = EmailSignature::create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Original',
        'content_html' => '<p>Old</p>',
        'is_default' => false,
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
    $previousDefault = EmailSignature::create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Old Default',
        'content_html' => '<p>Old</p>',
        'is_default' => true,
    ]);

    $other = EmailSignature::create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'Other',
        'content_html' => '<p>Other</p>',
        'is_default' => false,
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

// ── deleteSignature ───────────────────────────────────────────────────────────

it('deletes the signature and sends success notification', function (): void {
    $signature = EmailSignature::create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'To Delete',
        'content_html' => '<p>Bye</p>',
        'is_default' => false,
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

    $otherSignature = EmailSignature::create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
        'name' => 'Other Signature',
        'content_html' => '<p>Other</p>',
        'is_default' => false,
    ]);

    livewire(EmailSignaturesPage::class)
        ->callAction('deleteSignature', arguments: ['signature_id' => $otherSignature->id]);

    expect(EmailSignature::whereKey($otherSignature->id)->exists())->toBeTrue();
});

// ── mount / page scope ────────────────────────────────────────────────────────

it('shows only the authenticated user\'s signatures on mount', function (): void {
    $mySignature = EmailSignature::create([
        'connected_account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'name' => 'My Signature',
        'content_html' => '<p>Mine</p>',
        'is_default' => false,
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

    $otherSignature = EmailSignature::create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
        'name' => 'Other Signature',
        'content_html' => '<p>Theirs</p>',
        'is_default' => false,
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

    $teammateSignature = EmailSignature::create([
        'connected_account_id' => $otherAccount->id,
        'user_id' => $otherUser->id,
        'name' => 'Teammate Signature',
        'content_html' => '<p>Teammate</p>',
        'is_default' => false,
    ]);

    $component = livewire(EmailSignaturesPage::class);

    $ids = $component->get('signatures')->pluck('id')->all();

    expect($ids)->not->toContain($teammateSignature->id);
});
