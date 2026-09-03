<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Collection;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

mutates(VisibleEmailScope::class);

beforeEach(function (): void {
    $this->viewer = User::factory()->withTeam()->create();
    $this->team = $this->viewer->currentTeam;
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $this->coworker = User::factory()->create();
    $this->coworker->teams()->attach($this->team);
    $this->coworker->forceFill(['current_team_id' => $this->team->id])->save();

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->coworker->id,
    ]));

    $this->makeCoworkerEmail = function (array $participants): Email {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->coworker->id,
            'connected_account_id' => $this->account->getKey(),
            'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
            'is_internal' => false,
        ]);

        foreach ($participants as $participantAddress) {
            EmailParticipant::query()->create([
                'email_id' => $email->id,
                'email_address' => $participantAddress,
                'name' => null,
                'role' => EmailParticipantRole::FROM,
            ]);
        }

        return $email;
    };
});

function visibleTo(User $viewer): Collection
{
    return Email::query()
        ->withGlobalScope('visible', new VisibleEmailScope($viewer))
        ->get();
}

it('hides a coworker email when all participants are protected', function (): void {
    TeamEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerEmail)(['vip@contact.com']);
    $normal = ($this->makeCoworkerEmail)(['normal@contact.com']);

    $visibleIds = visibleTo($this->viewer)->modelKeys();

    expect($visibleIds)->toContain($normal->id)
        ->not->toContain($protected->id);
});

it('shows a coworker email when only some participants are protected', function (): void {
    TeamEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $mixed = ($this->makeCoworkerEmail)(['vip@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($mixed->id);
});

it('hides a coworker email when any participant matches a blocked entry', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $blocked = ($this->makeCoworkerEmail)(['blocked@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($blocked->id);
});

it('hides a coworker email whose participant matches a protected domain', function (): void {
    TeamEmailBlocklist::factory()->protected()->domain('secret.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerEmail)(['anyone@secret.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($protected->id);
});

it('hides a coworker email whose participant matches an inferred workspace domain', function (): void {
    $this->coworker->update(['email' => 'coworker@thefireflytech.com']);

    $protected = ($this->makeCoworkerEmail)(['client@thefireflytech.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($protected->id);
});

it('still shows a protected email to its owner', function (): void {
    TeamEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerEmail)(['vip@contact.com']);

    expect(visibleTo($this->coworker)->modelKeys())->toContain($protected->id);
});

it('hides a workspace-blocked email from its owner', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'team_id' => $this->team->id,
        'created_by' => $this->viewer->id,
    ]);

    $blocked = ($this->makeCoworkerEmail)(['blocked@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->coworker)->modelKeys())->not->toContain($blocked->id);
});

it('hides a mailbox-blocklisted email from its owner', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->coworker->id,
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $blocked = ($this->makeCoworkerEmail)(['spam@badactor.com']);

    expect(visibleTo($this->coworker)->modelKeys())->not->toContain($blocked->id);
});

it('hides a mailbox-blocklisted email from teammates', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->coworker->id,
        'team_id' => $this->team->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $blocked = ($this->makeCoworkerEmail)(['spam@badactor.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($blocked->id);
});
