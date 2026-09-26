<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\EmailPolicy;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailShare;

mutates(EmailPolicy::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::FULL,
        'is_internal' => false,
    ]);
});

it('allows the owner to view an email in their workspace', function (): void {
    expect($this->owner->can('view', $this->email))->toBeTrue()
        ->and($this->owner->can('viewSubject', $this->email))->toBeTrue()
        ->and($this->owner->can('viewBody', $this->email))->toBeTrue()
        ->and($this->owner->can('share', $this->email))->toBeTrue();
});

it('denies every email ability to a user from another workspace', function (): void {
    $outsider = User::factory()->withWorkspace()->create();

    expect($outsider->can('view', $this->email))->toBeFalse()
        ->and($outsider->can('viewSubject', $this->email))->toBeFalse()
        ->and($outsider->can('viewBody', $this->email))->toBeFalse()
        ->and($outsider->can('share', $this->email))->toBeFalse()
        ->and($outsider->can('requestAccess', $this->email))->toBeFalse();
});

it('denies every view ability to a teammate with a private share override', function (): void {
    $teammate = User::factory()->create();
    $teammate->workspaces()->attach($this->workspace);
    $teammate->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    EmailShare::factory()->tier(EmailPrivacyTier::PRIVATE)->create([
        'email_id' => $this->email->getKey(),
        'shared_by' => $this->owner->id,
        'shared_with' => $teammate->id,
    ]);

    expect($teammate->can('view', $this->email))->toBeFalse()
        ->and($teammate->can('viewSubject', $this->email))->toBeFalse()
        ->and($teammate->can('viewBody', $this->email))->toBeFalse()
        ->and($teammate->can('requestAccess', $this->email))->toBeFalse();
});

it('still allows a teammate to view a shared-tier email in the same workspace', function (): void {
    $teammate = User::factory()->create();
    $teammate->workspaces()->attach($this->workspace);
    $teammate->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    expect($teammate->can('view', $this->email))->toBeTrue()
        ->and($teammate->can('viewBody', $this->email))->toBeTrue()
        ->and($teammate->can('share', $this->email))->toBeFalse();
});

it('gives a teammate full access when they already synced the same message', function (): void {
    $this->email->update(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY]);

    $teammate = User::factory()->create();
    $teammate->workspaces()->attach($this->workspace);
    $teammate->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $teammateAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $teammate->id,
    ]));

    Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $teammate->id,
        'connected_account_id' => $teammateAccount->getKey(),
        'rfc_message_id' => $this->email->rfc_message_id,
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'is_internal' => false,
    ]);

    expect($teammate->can('viewBody', $this->email))->toBeTrue()
        ->and($teammate->can('requestAccess', $this->email))->toBeFalse()
        ->and($teammate->can('share', $this->email))->toBeFalse();
});
