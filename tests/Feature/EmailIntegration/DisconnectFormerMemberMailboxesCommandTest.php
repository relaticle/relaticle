<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Relaticle\EmailIntegration\Console\Commands\DisconnectFormerMemberMailboxesCommand;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(DisconnectFormerMemberMailboxesCommand::class);

beforeEach(function (): void {
    Http::fake();

    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;
    $this->former = User::factory()->withPersonalWorkspace()->create();

    $connect = fn (User $user, string $workspaceId, string $address): ConnectedAccount => ConnectedAccount::withoutEvents(
        fn (): ConnectedAccount => ConnectedAccount::factory()->create([
            'workspace_id' => $workspaceId,
            'user_id' => $user->getKey(),
            'email_address' => $address,
            'provider_account_id' => $address,
        ]),
    );

    $this->workspace->users()->attach($member = User::factory()->create(), ['role' => 'member']);

    $this->formerMailbox = $connect($this->former, $this->workspace->getKey(), 'dana@northwind.test');
    $this->formerMailboxElsewhere = $connect($this->former, $this->former->current_workspace_id, 'dana@northwind.test');
    $this->ownerMailbox = $connect($this->owner, $this->workspace->getKey(), 'olivia@northwind.test');
    $this->memberMailbox = $connect($member, $this->workspace->getKey(), 'maya@northwind.test');
});

it('reports the mailboxes of former members without disconnecting them', function (): void {
    $this->artisan('email:disconnect-former-member-mailboxes')
        ->expectsOutputToContain('1 mailbox(es) belong to former members.')
        ->assertSuccessful();

    expect($this->formerMailbox->fresh()->trashed())->toBeFalse()
        ->and($this->formerMailbox->fresh()->access_token)->not->toBeNull();
});

it('disconnects only the mailboxes whose owner left the workspace', function (): void {
    $this->artisan('email:disconnect-former-member-mailboxes', ['--force' => true])
        ->expectsOutputToContain('1 mailbox(es) of former members disconnected.')
        ->assertSuccessful();

    $disconnected = ConnectedAccount::withTrashed()->findOrFail($this->formerMailbox->getKey());

    expect($disconnected->trashed())->toBeTrue()
        ->and($disconnected->access_token)->toBeNull()
        ->and($disconnected->refresh_token)->toBeNull()
        ->and($this->formerMailboxElsewhere->fresh()->trashed())->toBeFalse()
        ->and($this->ownerMailbox->fresh()->trashed())->toBeFalse()
        ->and($this->memberMailbox->fresh()->trashed())->toBeFalse();

    $this->artisan('email:disconnect-former-member-mailboxes', ['--force' => true])
        ->expectsOutputToContain('0 mailbox(es) of former members disconnected.')
        ->assertSuccessful();
});
