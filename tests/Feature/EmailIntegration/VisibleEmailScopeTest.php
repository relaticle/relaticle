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
use Relaticle\EmailIntegration\Models\WorkspaceEmailBlocklist;

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
    WorkspaceEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
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
    WorkspaceEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $mixed = ($this->makeCoworkerEmail)(['vip@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($mixed->id);
});

it('hides a coworker email when any participant matches a blocked entry', function (): void {
    WorkspaceEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $blocked = ($this->makeCoworkerEmail)(['blocked@contact.com', 'normal@contact.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($blocked->id);
});

it('hides a coworker email whose participant matches a protected domain', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->domain('secret.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerEmail)(['anyone@secret.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($protected->id);
});

it('shows a coworker email from a subdomain when domain block has include subdomains off', function (): void {
    WorkspaceEmailBlocklist::factory()->blocked()->domain('temuemail.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $subdomainSender = ($this->makeCoworkerEmail)(['temu@commerce.temuemail.com']);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($subdomainSender->id);
});

it('hides a coworker email from a subdomain when domain block has include subdomains on', function (): void {
    WorkspaceEmailBlocklist::factory()->blocked()->domain('temuemail.com')->includeSubdomains()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $subdomainSender = ($this->makeCoworkerEmail)(['temu@commerce.temuemail.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($subdomainSender->id);
});

it('treats like wildcards in a blocked domain as literal characters', function (string $blockedDomain, bool $includeSubdomains, string $lookalike): void {
    $factory = WorkspaceEmailBlocklist::factory()->blocked()->domain($blockedDomain);

    ($includeSubdomains ? $factory->includeSubdomains() : $factory)->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $lookalikeEmail = ($this->makeCoworkerEmail)([$lookalike]);

    expect(visibleTo($this->viewer)->modelKeys())->toContain($lookalikeEmail->id);
})->with([
    'underscore' => ['exa_ple.com', false, 'user@exaxple.com'],
    'underscore with subdomains' => ['exa_ple.com', true, 'user@mail.exaxple.com'],
    'percent' => ['%', true, 'user@partner.test'],
]);

it('still hides a coworker email that matches a blocked domain containing an underscore literally', function (): void {
    WorkspaceEmailBlocklist::factory()->blocked()->domain('exa_ple.com')->includeSubdomains()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $exact = ($this->makeCoworkerEmail)(['user@exa_ple.com']);
    $subdomain = ($this->makeCoworkerEmail)(['user@mail.exa_ple.com']);

    expect(visibleTo($this->viewer)->modelKeys())
        ->not->toContain($exact->id)
        ->not->toContain($subdomain->id);
});

it('hides a coworker email whose participant matches an inferred workspace domain', function (): void {
    $this->coworker->update(['email' => 'coworker@thefireflytech.com']);

    $protected = ($this->makeCoworkerEmail)(['client@thefireflytech.com']);

    expect(visibleTo($this->viewer)->modelKeys())->not->toContain($protected->id);
});

it('treats like wildcards in an inferred workspace domain as literal characters', function (): void {
    $this->coworker->update(['email' => 'coworker@exa_ple.com']);

    $lookalike = ($this->makeCoworkerEmail)(['client@exaxple.com']);
    $member = ($this->makeCoworkerEmail)(['client@exa_ple.com']);

    expect(visibleTo($this->viewer)->modelKeys())
        ->toContain($lookalike->id)
        ->not->toContain($member->id);
});

it('still shows a protected email to its owner', function (): void {
    WorkspaceEmailBlocklist::factory()->protected()->email('vip@contact.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    $protected = ($this->makeCoworkerEmail)(['vip@contact.com']);

    expect(visibleTo($this->coworker)->modelKeys())->toContain($protected->id);
});

it('hides a workspace-blocked email from its owner', function (): void {
    WorkspaceEmailBlocklist::factory()->blocked()->email('blocked@contact.com')->create([
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

it('hides mail from an address the workspace blocks after an earlier read', function (): void {
    $email = ($this->makeCoworkerEmail)(['late-block@contact.com']);

    expect(visibleTo($this->coworker)->modelKeys())->toContain($email->id);

    WorkspaceEmailBlocklist::factory()->blocked()->email('late-block@contact.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->viewer->id,
    ]);

    expect(visibleTo($this->coworker)->modelKeys())->not->toContain($email->id);
});

it('hides mail from an address the mailbox blocks after an earlier read', function (): void {
    $email = ($this->makeCoworkerEmail)(['late-spam@badactor.com']);

    expect(visibleTo($this->coworker)->modelKeys())->toContain($email->id);

    EmailBlocklist::factory()->email('late-spam@badactor.com')->create([
        'user_id' => $this->coworker->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    expect(visibleTo($this->coworker)->modelKeys())->not->toContain($email->id);
});

it('ignores another workspace blocking the same address', function (): void {
    $otherOwner = User::factory()->withWorkspace()->create();

    WorkspaceEmailBlocklist::factory()->blocked()->email('shared@contact.com')->create([
        'workspace_id' => $otherOwner->current_workspace_id,
        'created_by' => $otherOwner->id,
    ]);

    $email = ($this->makeCoworkerEmail)(['shared@contact.com']);

    expect(visibleTo($this->coworker)->modelKeys())->toContain($email->id);
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
