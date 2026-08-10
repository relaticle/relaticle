<?php

declare(strict_types=1);

use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Relaticle\EmailIntegration\Enums\EmailCreationSource;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

mutates(SendEmailJob::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
    ]));
});

it('records exception class and message on the email when the job fails', function (): void {
    $email = Email::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Outbound',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    $exception = new RuntimeException('boom');

    (new SendEmailJob($email->getKey()))->failed($exception);

    expect($email->fresh())
        ->status->toBe(EmailStatus::FAILED)
        ->last_error->toBe('RuntimeException: boom');
});

it('logs the full exception when the job fails', function (): void {
    $email = Email::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'connected_account_id' => $this->account->id,
        'subject' => 'Outbound',
        'direction' => EmailDirection::OUTBOUND,
        'status' => EmailStatus::SENDING,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'creation_source' => EmailCreationSource::COMPOSE,
    ]);

    $exception = new RuntimeException('kaboom');

    Log::shouldReceive('error')
        ->once()
        ->withArgs(function (string $message, array $context) use ($email, $exception): bool {
            return $message === 'SendEmailJob failed'
                && $context['email_id'] === $email->getKey()
                && $context['exception'] === $exception;
        });

    (new SendEmailJob($email->getKey()))->failed($exception);
});
