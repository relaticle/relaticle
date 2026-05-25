<?php

declare(strict_types=1);

use App\Enums\TagAction;
use App\Jobs\Email\ModifySubscriberTagsJob;
use Spatie\MailcoachSdk\Facades\Mailcoach;

mutates(ModifySubscriberTagsJob::class);

beforeEach(function () {
    config(['mailcoach-sdk.api_token' => 'fake-token', 'mailcoach-sdk.endpoint' => 'https://fake.mailcoach.test']);
});

test('it calls the Mailcoach add tags endpoint', function () {
    Mailcoach::shouldReceive('post')
        ->once()
        ->with('subscribers/test-uuid-123/tags', ['tags' => ['has-crm-data']])
        ->andReturnNull();

    (new ModifySubscriberTagsJob('test-uuid-123', ['has-crm-data'], TagAction::Add))->handle();
});

test('it sends multiple tags in a single add call', function () {
    Mailcoach::shouldReceive('post')
        ->once()
        ->with('subscribers/test-uuid-456/tags', ['tags' => ['active-7d', 'has-crm-data']])
        ->andReturnNull();

    (new ModifySubscriberTagsJob('test-uuid-456', ['active-7d', 'has-crm-data'], TagAction::Add))->handle();
});

test('it calls the Mailcoach delete tags endpoint for remove action', function () {
    Mailcoach::shouldReceive('delete')
        ->once()
        ->with('subscribers/test-uuid-123/tags', ['tags' => ['dormant']])
        ->andReturnNull();

    (new ModifySubscriberTagsJob('test-uuid-123', ['dormant'], TagAction::Remove))->handle();
});
