<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\LinkEmailAction;
use Relaticle\EmailIntegration\Actions\LinkMeetingAction;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Jobs\RelinkMailboxHistoryJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Models\MeetingAttendee;

mutates(RelinkMailboxHistoryJob::class, LinkEmailAction::class, LinkMeetingAction::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

it('creates a person from stored outbound mail after the workspace switches to Selective', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::None]);

    $email = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'prospect@partner.com',
        'name' => 'New Prospect',
    ]);

    app(LinkEmailAction::class)->execute($email);
    expect($email->fresh()->linked_at)->not->toBeNull()
        ->and(People::query()->where('team_id', $this->team->id)->where('name', 'New Prospect')->exists())->toBeFalse();

    $this->team->update(['contact_creation_mode' => ContactCreationMode::Selective]);

    (new RelinkMailboxHistoryJob($this->account))->handle(
        app(LinkEmailAction::class),
        app(LinkMeetingAction::class),
    );

    expect(People::query()->where('team_id', $this->team->id)->where('name', 'New Prospect')->exists())->toBeTrue();
});

it('creates a person from a stored meeting after the workspace switches to Selective', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::None]);

    $meeting = Meeting::factory()->create([
        'team_id' => $this->account->team_id,
        'connected_account_id' => $this->account->getKey(),
    ]);
    MeetingAttendee::factory()->create([
        'meeting_id' => $meeting->getKey(),
        'email_address' => 'guest@acme.com',
        'is_self' => false,
    ]);

    app(LinkMeetingAction::class)->execute($meeting->fresh());
    expect(People::query()->where('team_id', $this->team->id)->count())->toBe(0);

    $this->team->update(['contact_creation_mode' => ContactCreationMode::Selective]);

    (new RelinkMailboxHistoryJob($this->account))->handle(
        app(LinkEmailAction::class),
        app(LinkMeetingAction::class),
    );

    expect(People::query()->where('team_id', $this->team->id)->count())->toBe(1);
});

it('does not relink mail stored on another connected account', function (): void {
    $this->team->update(['contact_creation_mode' => ContactCreationMode::None]);

    $otherAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));

    $email = Email::factory()->outbound()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $otherAccount->getKey(),
    ]);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'other-mailbox@partner.com',
        'name' => 'Other Mailbox Prospect',
    ]);

    app(LinkEmailAction::class)->execute($email);

    $this->team->update(['contact_creation_mode' => ContactCreationMode::Selective]);

    (new RelinkMailboxHistoryJob($this->account))->handle(
        app(LinkEmailAction::class),
        app(LinkMeetingAction::class),
    );

    expect(People::query()->where('team_id', $this->team->id)->where('name', 'Other Mailbox Prospect')->exists())->toBeFalse();
});
