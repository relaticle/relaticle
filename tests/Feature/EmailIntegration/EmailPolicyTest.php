<?php

declare(strict_types=1);

use App\Models\User;
use App\Policies\EmailPolicy;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(EmailPolicy::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->email = Email::factory()->create([
        'team_id' => $this->team->id,
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
    $outsider = User::factory()->withTeam()->create();

    expect($outsider->can('view', $this->email))->toBeFalse()
        ->and($outsider->can('viewSubject', $this->email))->toBeFalse()
        ->and($outsider->can('viewBody', $this->email))->toBeFalse()
        ->and($outsider->can('share', $this->email))->toBeFalse()
        ->and($outsider->can('requestAccess', $this->email))->toBeFalse();
});

it('still allows a teammate to view a shared-tier email in the same workspace', function (): void {
    $teammate = User::factory()->create();
    $teammate->teams()->attach($this->team);
    $teammate->forceFill(['current_team_id' => $this->team->id])->save();

    expect($teammate->can('view', $this->email))->toBeTrue()
        ->and($teammate->can('viewBody', $this->email))->toBeTrue()
        ->and($teammate->can('share', $this->email))->toBeFalse();
});
