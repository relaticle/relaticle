<?php

declare(strict_types=1);

use App\Jobs\SendEmailJob;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

beforeEach(function (): void {
    Bus::fake();

    $this->user = User::factory()->withTeam()->create();
    $this->team = $this->user->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'status' => 'active',
    ]));

    $this->makeOutbound = function (EmailStatus $status, ?string $providerMessageId, int $sendingMinutesAgo): Email {
        $email = Email::factory()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'connected_account_id' => $this->account->getKey(),
            'direction' => EmailDirection::OUTBOUND,
            'status' => $status,
            'provider_message_id' => $providerMessageId,
            'scheduled_for' => null,
            'attempts' => 1,
        ]);

        // Raw update so updated_at lands in the past without being auto-touched.
        Email::query()->whereKey($email->getKey())->update([
            'updated_at' => now()->subMinutes($sendingMinutesAgo),
        ]);

        return $email->refresh();
    };
});

it('reclaims a SENDING email whose worker died and re-dispatches it', function (): void {
    $stuck = ($this->makeOutbound)(EmailStatus::SENDING, null, 30);

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    // The dispatcher only ever selects QUEUED rows, so a re-dispatch proves the
    // stuck SENDING row was reclaimed to QUEUED first.
    Bus::assertDispatched(fn (SendEmailJob $job): bool => $job->emailId === $stuck->getKey());
});

it('leaves a freshly-claimed SENDING email in flight', function (): void {
    $fresh = ($this->makeOutbound)(EmailStatus::SENDING, null, 1);

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($fresh->refresh()->status)->toBe(EmailStatus::SENDING);
    Bus::assertNotDispatched(SendEmailJob::class);
});

it('never reclaims a SENDING email the provider already accepted', function (): void {
    $delivered = ($this->makeOutbound)(EmailStatus::SENDING, 'provider-msg-1', 30);

    $this->artisan('email:dispatch-outbox')->assertSuccessful();

    expect($delivered->refresh()->status)->toBe(EmailStatus::SENDING);
    Bus::assertNotDispatched(SendEmailJob::class);
});
