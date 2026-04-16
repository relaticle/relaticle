<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\CreateSignatureAction;
use Relaticle\EmailIntegration\Actions\UpdateSignatureAction;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\EmailSignature;

mutates(CreateSignatureAction::class, UpdateSignatureAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));
});

it('creates a signature record', function (): void {
    $signature = app(CreateSignatureAction::class)->execute($this->account, [
        'name' => 'Work Signature',
        'content_html' => '<p>Best regards, Test</p>',
        'is_default' => false,
    ]);

    expect($signature)->toBeInstanceOf(EmailSignature::class)
        ->and($signature->name)->toBe('Work Signature')
        ->and($signature->content_html)->toBe('<p>Best regards, Test</p>')
        ->and($signature->connected_account_id)->toBe($this->account->getKey())
        ->and($signature->user_id)->toBe($this->user->getKey())
        ->and($signature->is_default)->toBeFalse();
});

it('sets new signature as default and unsets the previous default', function (): void {
    $existingDefault = EmailSignature::create([
        'connected_account_id' => $this->account->getKey(),
        'user_id' => $this->user->id,
        'name' => 'Old Default',
        'content_html' => '<p>Old</p>',
        'is_default' => true,
    ]);

    $newDefault = app(CreateSignatureAction::class)->execute($this->account, [
        'name' => 'New Default',
        'content_html' => '<p>New</p>',
        'is_default' => true,
    ]);

    expect($newDefault->is_default)->toBeTrue()
        ->and($existingDefault->fresh()->is_default)->toBeFalse();
});

it('allows multiple non-default signatures', function (): void {
    app(CreateSignatureAction::class)->execute($this->account, [
        'name' => 'Sig One',
        'content_html' => '<p>One</p>',
        'is_default' => false,
    ]);

    app(CreateSignatureAction::class)->execute($this->account, [
        'name' => 'Sig Two',
        'content_html' => '<p>Two</p>',
        'is_default' => false,
    ]);

    expect(EmailSignature::where('connected_account_id', $this->account->getKey())->count())->toBe(2)
        ->and(EmailSignature::where('connected_account_id', $this->account->getKey())->where('is_default', true)->count())->toBe(0);
});

it('updates signature name', function (): void {
    $signature = EmailSignature::create([
        'connected_account_id' => $this->account->getKey(),
        'user_id' => $this->user->id,
        'name' => 'Original Name',
        'content_html' => '<p>Content</p>',
        'is_default' => false,
    ]);

    $updated = app(UpdateSignatureAction::class)->execute($signature, ['name' => 'Updated Name']);

    expect($updated->name)->toBe('Updated Name');
});

it('setting is_default clears other defaults for the same account', function (): void {
    $sigA = EmailSignature::create([
        'connected_account_id' => $this->account->getKey(),
        'user_id' => $this->user->id,
        'name' => 'Sig A',
        'content_html' => '<p>A</p>',
        'is_default' => true,
    ]);

    $sigB = EmailSignature::create([
        'connected_account_id' => $this->account->getKey(),
        'user_id' => $this->user->id,
        'name' => 'Sig B',
        'content_html' => '<p>B</p>',
        'is_default' => false,
    ]);

    app(UpdateSignatureAction::class)->execute($sigB, ['is_default' => true]);

    expect($sigA->fresh()->is_default)->toBeFalse()
        ->and($sigB->fresh()->is_default)->toBeTrue();
});
