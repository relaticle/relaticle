<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Data\FetchedEmailData;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;
use Relaticle\EmailIntegration\Services\EmailSendingService;

mutates(EmailSendingService::class);

/**
 * Records how many times sendMessage() is invoked and what findSentMessage()
 * should return, standing in for a real Gmail/Graph provider.
 */
final class FakeMailService implements MailServiceInterface
{
    public int $sendCount = 0;

    /**
     * @param  array{provider_message_id: string, thread_id: string, rfc_message_id: string}|null  $findResult
     */
    public function __construct(private readonly ?array $findResult = null) {}

    public function fetchDelta(string $cursor): MailDeltaResult
    {
        throw new LogicException('unused');
    }

    public function fetchMessage(string $providerMessageId): FetchedEmailData
    {
        throw new LogicException('unused');
    }

    public function initialBackfill(int $daysBack): array
    {
        throw new LogicException('unused');
    }

    public function sendMessage(array $data): array
    {
        $this->sendCount++;

        return [
            'provider_message_id' => 'sent-123',
            'thread_id' => 'thread-123',
            'rfc_message_id' => $data['rfc_message_id'] ?? '<derived@example.com>',
        ];
    }

    public function findSentMessage(string $rfcMessageId): ?array
    {
        return $this->findResult;
    }

    public function downloadAttachment(string $providerMessageId, string $providerAttachmentId): string
    {
        return '';
    }
}

function bindFakeMailService(FakeMailService $fake): void
{
    app()->bind(MailServiceFactoryInterface::class, fn (): MailServiceFactoryInterface => new class($fake) implements MailServiceFactoryInterface
    {
        public function __construct(private readonly MailServiceInterface $service) {}

        public function make(ConnectedAccount $account): MailServiceInterface
        {
            return $this->service;
        }
    });
}

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

function makeOutboundEmail(int $attempts): Email
{
    /** @var Email $email */
    $email = Email::factory()->outbound()->create([
        'team_id' => test()->team->id,
        'user_id' => test()->user->id,
        'connected_account_id' => test()->account->getKey(),
        'rfc_message_id' => '<idem-key@example.com>',
        'provider_message_id' => null,
        'thread_id' => null,
        'status' => EmailStatus::SENDING,
        'sent_at' => null,
        'attempts' => $attempts,
    ]);

    $email->body()->create(['body_text' => 'hi', 'body_html' => '<p>hi</p>']);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'recipient@partner.com',
    ]);

    return $email->fresh(['body', 'participants', 'connectedAccount']);
}

it('dispatches to the provider once on a first attempt', function (): void {
    $fake = new FakeMailService;
    bindFakeMailService($fake);

    $email = makeOutboundEmail(attempts: 1);

    $sent = app(EmailSendingService::class)->send($email);

    expect($fake->sendCount)->toBe(1)
        ->and($sent->status)->toBe(EmailStatus::SENT)
        ->and($sent->provider_message_id)->toBe('sent-123');
});

it('does not re-dispatch on retry when the provider already has the message', function (): void {
    // A prior attempt delivered but crashed before persisting; the provider lookup
    // finds the message by our Message-ID, so we adopt it instead of re-sending.
    $fake = new FakeMailService([
        'provider_message_id' => 'already-sent-999',
        'thread_id' => 'thread-existing',
        'rfc_message_id' => '<idem-key@example.com>',
    ]);
    bindFakeMailService($fake);

    $email = makeOutboundEmail(attempts: 2);

    $sent = app(EmailSendingService::class)->send($email);

    expect($fake->sendCount)->toBe(0)
        ->and($sent->status)->toBe(EmailStatus::SENT)
        ->and($sent->provider_message_id)->toBe('already-sent-999');
});

it('dispatches on retry when the provider has no record of the message', function (): void {
    $fake = new FakeMailService(findResult: null);
    bindFakeMailService($fake);

    $email = makeOutboundEmail(attempts: 2);

    $sent = app(EmailSendingService::class)->send($email);

    expect($fake->sendCount)->toBe(1)
        ->and($sent->status)->toBe(EmailStatus::SENT)
        ->and($sent->provider_message_id)->toBe('sent-123');
});
