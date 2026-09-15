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
use Relaticle\EmailIntegration\Models\EmailShare;
use Relaticle\EmailIntegration\Models\Scopes\VisibleEmailScope;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;

mutates(VisibleEmailScope::class);

beforeEach(function (): void {
    $this->viewer = User::factory()->withWorkspace()->create();
    $this->workspace = $this->viewer->currentWorkspace;
    $this->actingAs($this->viewer);
    Filament::setTenant($this->workspace);

    $this->coworker = User::factory()->create();
    $this->coworker->workspaces()->attach($this->workspace);
    $this->coworker->forceFill(['current_workspace_id' => $this->workspace->id])->save();

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->coworker->id,
    ]));

    $this->makeCoworkerEmail = function (array $participants): Email {
        $email = Email::factory()->create([
            'workspace_id' => $this->workspace->id,
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
        'workspace_id' => $this->workspace->id,
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
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $mixed = ($this->makeCoworkerEmail)(['vip@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($mixed->id);
});

it('hides a coworker email when any participant matches a blocked entry', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $blocked = ($this->makeCoworkerEmail)(['blocked@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($blocked->id);
});

it('hides a coworker email whose participant matches a protected domain', function (): void {
    TeamEmailBlocklist::factory()->protected()->domain('secret.com')->create([
        'workspace_id' => $this->workspace->id,
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
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerEmail)(['vip@contact.com']);

    expect(visibleTo($this->coworker)->modelKeys())->toContain($protected->id);
});

it('hides a workspace-blocked email from its owner', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $blocked = ($this->makeCoworkerEmail)(['blocked@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->coworker)->modelKeys())->not->toContain($blocked->id);
});

it('hides a mailbox-blocklisted email from its owner', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->coworker->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $blocked = ($this->makeCoworkerEmail)(['spam@badactor.com']);

    expect(visibleTo($this->coworker)->modelKeys())->not->toContain($blocked->id);
});

it('hides a mailbox-blocklisted email from teammates', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->coworker->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $blocked = ($this->makeCoworkerEmail)(['spam@badactor.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($blocked->id);
});

it('hides a coworker email when the viewer has a private per-teammate share', function (): void {
    $shared = ($this->makeCoworkerEmail)(['hidden-by-share@contact.com']);

    EmailShare::factory()->tier(EmailPrivacyTier::PRIVATE)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $shared->id,
        'shared_by' => $this->coworker->id,
        'shared_with' => $this->viewer->id,
    ]);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($shared->id);
});

it('shows a coworker email when the viewer has a non-private per-teammate share on a private email', function (): void {
    $email = Email::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->coworker->id,
        'connected_account_id' => $this->account->getKey(),
        'privacy_tier' => EmailPrivacyTier::PRIVATE,
        'is_internal' => false,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'shared-explicitly@contact.com',
        'name' => null,
        'role' => EmailParticipantRole::FROM,
    ]);

    EmailShare::factory()->tier(EmailPrivacyTier::METADATA_ONLY)->create([
        'workspace_id' => $this->workspace->id,
        'email_id' => $email->id,
        'shared_by' => $this->coworker->id,
        'shared_with' => $this->viewer->id,
    ]);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($email->id);
});

it('hides a coworker email when every participant is a connected mailbox address', function (): void {
    $this->account->update(['email_address' => 'whitesacks.dev@gmail.com']);

    ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->viewer->id,
        'email_address' => 'xyg@gmail.com',
    ]));

    $internal = ($this->makeCoworkerEmail)(['whitesacks.dev@gmail.com', 'xyg@gmail.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($internal->id);
});
