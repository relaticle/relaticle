<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Actions\SendEmailAction;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Services\EmailSendingService;

mutates(SendEmailAction::class, EmailSendingService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
    ]));
});

it('persists a queued Email row for the outbox', function (): void {
    $sendData = [
        'connected_account_id' => $this->account->id,
        'subject' => 'Hello World',
        'body_html' => '<p>Test</p>',
        'to' => [['email' => 'recipient@example.com', 'name' => 'Recipient']],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ];

    $email = app(SendEmailAction::class)->execute($sendData);

    expect($email->status)->toBe(EmailStatus::QUEUED)
        ->and($email->direction)->toBe(EmailDirection::OUTBOUND)
        ->and($email->connected_account_id)->toBe($this->account->id)
        ->and($email->subject)->toBe('Hello World')
        ->and($email->batch_id)->toBeNull();
});

it('links the queued email to a CRM record via emailables', function (): void {
    $person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);

    $sendData = [
        'connected_account_id' => $this->account->id,
        'subject' => 'Hello',
        'body_html' => '<p>Hi</p>',
        'to' => [['email' => 'jane@example.com', 'name' => 'Jane']],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ];

    $email = app(SendEmailAction::class)->execute($sendData, People::class, $person->id);

    $this->assertDatabaseHas('emailables', [
        'email_id' => $email->getKey(),
        'emailable_type' => People::class,
        'emailable_id' => $person->id,
        'link_source' => 'manual',
    ]);
});

it('throws when the user has hit the max queued limit', function (): void {
    config(['email-integration.outbox.max_queued_per_user' => 1]);

    Email::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Already queued',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::QUEUED,
        'privacy_tier' => EmailPrivacyTier::FULL,
    ]);

    expect(fn () => app(SendEmailAction::class)->execute([
        'connected_account_id' => $this->account->id,
        'subject' => 'Over limit',
        'body_html' => '<p>test</p>',
        'to' => [['email' => 'x@example.com', 'name' => null]],
        'cc' => [],
        'bcc' => [],
        'in_reply_to_email_id' => null,
        'creation_source' => EmailCreationSource::COMPOSE,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'batch_id' => null,
    ]))->toThrow(RuntimeException::class, 'queued');
});
