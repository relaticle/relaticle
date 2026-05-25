<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Data\MailDeltaResult;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Jobs\IncrementalEmailSyncJob;
use Relaticle\EmailIntegration\Jobs\StoreEmailJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceFactoryInterface;
use Relaticle\EmailIntegration\Services\Contracts\MailServiceInterface;

mutates(IncrementalEmailSyncJob::class);

it('advances the cursor and dispatches StoreEmailJob for new Microsoft messages', function (): void {
    Queue::fake();

    $user = User::factory()->withTeam()->create();
    $account = ConnectedAccount::factory()
        ->azure()
        ->for($user)
        ->create([
            'team_id' => $user->currentTeam->getKey(),
            'access_token' => 'a',
            'refresh_token' => 'r',
            'token_expires_at' => now()->addHour(),
            'sync_cursor' => 'old-cursor',
            'status' => EmailAccountStatus::ACTIVE,
            'capabilities' => ['email' => true, 'calendar' => false],
        ]);

    $service = Mockery::mock(MailServiceInterface::class);
    $service->shouldReceive('fetchDelta')->with('old-cursor')->andReturn(new MailDeltaResult(
        messageIds: collect(['M1', 'M2']),
        readMessageIds: collect([]),
        newCursor: 'new-cursor',
    ));

    $factory = Mockery::mock(MailServiceFactoryInterface::class);
    $factory->shouldReceive('make')->with(Mockery::on(fn (ConnectedAccount $a): bool => $a->is($account)))->andReturn($service);
    $this->app->instance(MailServiceFactoryInterface::class, $factory);

    (new IncrementalEmailSyncJob($account))->handle($factory);

    Queue::assertPushed(StoreEmailJob::class, 2);

    expect($account->refresh()->sync_cursor)->toBe('new-cursor');
});
