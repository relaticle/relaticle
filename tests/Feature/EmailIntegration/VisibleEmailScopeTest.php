<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\ProtectedRecipient;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;

beforeEach(function (): void {
    $this->viewer = User::factory()->withTeam()->create();
    $this->team = $this->viewer->currentTeam;
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $this->coworker = User::factory()->create();
    $this->coworker->teams()->attach($this->team);
    // The coworker's active tenant is this shared team (the scope filters by it).
    $this->coworker->forceFill(['current_team_id' => $this->team->id])->save();

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->coworker->id,
    ]));

    $this->makeCoworkerEmail = function (string $participantAddress): Email {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->coworker->id,
            'connected_account_id' => $this->account->getKey(),
            // Not PRIVATE and not internal — passes the public gate on its own.
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'is_internal' => false,
        ]);

        EmailParticipant::query()->create([
            'email_id' => $email->id,
            'email_address' => $participantAddress,
            'name' => null,
            'role' => EmailParticipantRole::FROM,
        ]);

        return $email;
    };
});

function visibleTo(User $viewer): Collection
{
    return Email::query()
        ->withGlobalScope('visible', new VisibleEmailScope($viewer))
        ->get();
}

it('hides a coworker email addressed to a protected recipient', function (): void {
    ProtectedRecipient::query()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
        'type' => 'email',
        'value' => 'vip@contact.com',
    ]);

    $protected = ($this->makeCoworkerEmail)('vip@contact.com');
    $normal = ($this->makeCoworkerEmail)('normal@contact.com');

    $visibleIds = visibleTo($this->viewer)->modelKeys();

    expect($visibleIds)->toContain($normal->id)
        ->not->toContain($protected->id);
});

it('hides a coworker email whose participant matches a protected domain', function (): void {
    ProtectedRecipient::query()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
        'type' => 'domain',
        'value' => 'secret.com',
    ]);

    $protected = ($this->makeCoworkerEmail)('anyone@secret.com');

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($protected->id);
});

it('still shows a protected email to its owner', function (): void {
    ProtectedRecipient::query()->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
        'type' => 'email',
        'value' => 'vip@contact.com',
    ]);

    $protected = ($this->makeCoworkerEmail)('vip@contact.com');

    // The coworker owns it, so the owner branch wins over the protected-recipient gate.
    expect(visibleTo($this->coworker)->modelKeys())->toContain($protected->id);
});
