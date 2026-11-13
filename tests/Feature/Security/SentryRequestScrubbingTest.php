<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventType;
use Sentry\Laravel\Http\LaravelRequestFetcher;
use Sentry\SentrySdk;
use Sentry\State\HubInterface;
use Sentry\Transport\Result;
use Sentry\Transport\ResultStatus;
use Sentry\Transport\TransportInterface;

final class SentryTransportSpy implements TransportInterface
{
    /** @var list<Event> */
    public array $events = [];

    public function send(Event $event): Result
    {
        $this->events[] = $event;

        return new Result(ResultStatus::success(), $event);
    }

    public function close(?int $timeout = null): Result
    {
        return new Result(ResultStatus::success());
    }
}

function sentryHubSending(SentryTransportSpy $transport, ServerRequest $request): HubInterface
{
    config(['sentry.dsn' => 'https://public@sentry.invalid/1']);

    app()->extend(ClientBuilder::class, fn (ClientBuilder $builder): ClientBuilder => $builder->setTransport($transport));
    app()->forgetInstance(HubInterface::class);
    app()->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $request);

    $hub = resolve(HubInterface::class);
    SentrySdk::setCurrentHub($hub);

    return $hub;
}

/**
 * @param  array<string, string>  $body
 */
function sentryRequest(string $method, string $uri, array $body = []): ServerRequest
{
    return new ServerRequest($method, 'https://relaticle.test'.$uri, [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Content-Length' => '120',
    ], null, '1.1', ['REMOTE_ADDR' => '203.0.113.9'])->withParsedBody($body);
}

it('keeps request bodies out of error reports', function (): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('POST', '/app/login', [
        'password' => 'hunter2-secret',
        'code' => '123456',
    ]));

    $hub->captureException(new RuntimeException('boom'));

    expect($transport->events)->toHaveCount(1)
        ->and($transport->events[0]->getRequest())->not->toHaveKey('data')
        ->and(json_encode($transport->events[0]->getRequest()))->not->toContain('hunter2-secret');
});

it('keeps query strings out of error reports', function (): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('GET', '/app/password-reset/reset?token=reset-secret&email=jo%40example.com'));

    $hub->captureException(new RuntimeException('boom'));

    $request = $transport->events[0]->getRequest();

    expect($request)->not->toHaveKey('query_string')
        ->and($request['url'])->toBe('https://relaticle.test/app/password-reset/reset')
        ->and(json_encode($request))->not->toContain('reset-secret');
});

it('redacts the token segment of tokenised paths in error reports', function (string $path, string $expected, string $secret): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('GET', $path));

    $hub->captureException(new RuntimeException('boom'));

    $request = $transport->events[0]->getRequest();

    expect($request['url'])->toBe('https://relaticle.test'.$expected)
        ->and(json_encode($request))->not->toContain($secret);
})->with([
    'invitation' => ['/invitations/inv-secret-token', '/invitations/{token}', 'inv-secret-token'],
    'invitation account switch' => ['/invitations/inv-secret-token/switch-account', '/invitations/{token}/switch-account', 'inv-secret-token'],
    'join link' => ['/join/join-secret-token', '/join/{token}', 'join-secret-token'],
    'app email verification' => ['/app/email-verification/verify/42/hash-secret?signature=sig-secret', '/app/email-verification/verify/42/{hash}', 'hash-secret'],
    'email verification' => ['/email/verify/42/hash-secret', '/email/verify/42/{hash}', 'hash-secret'],
    'sysadmin email verification' => ['/sysadmin/email-verification/verify/42/hash-secret', '/sysadmin/email-verification/verify/42/{hash}', 'hash-secret'],
    'email change' => ['/app/email-change-verification/verify/42/new%40example.com', '/app/email-change-verification/verify/42/{email}', 'new%40example.com'],
    'email change block' => ['/app/email-change-verification/verify/42/new%40example.com/block', '/app/email-change-verification/verify/42/{email}/block', 'new%40example.com'],
]);

it('strips the query and path token from the referer header', function (): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('POST', '/livewire/update')->withHeader('Referer', 'https://relaticle.test/join/join-secret-token?x=1'));

    $hub->captureException(new RuntimeException('boom'));

    expect(json_encode($transport->events[0]->getRequest()))->not->toContain('join-secret-token');
});

it('redacts the path token of a transaction name and its trace data', function (): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('GET', '/join/join-secret-token?x=1'));

    $event = Event::createTransaction();
    $event->setTransaction('/join/join-secret-token');
    $event->setContext('trace', ['data' => ['url' => '/join/join-secret-token'], 'op' => 'http.server']);

    $hub->captureEvent($event);

    $sent = $transport->events[0];

    expect($sent->getType())->toBe(EventType::transaction())
        ->and($sent->getTransaction())->toBe('/join/{token}')
        ->and($sent->getContexts()['trace']['data']['url'])->toBe('/join/{token}')
        ->and(json_encode($sent->getRequest()))->not->toContain('join-secret-token');
});

it('configures sentry to send no request body and scrub through static callables', function (): void {
    expect(config('sentry.max_request_body_size'))->toBe('none')
        ->and(is_callable(config('sentry.before_send')))->toBeTrue()
        ->and(is_callable(config('sentry.before_send_transaction')))->toBeTrue()
        ->and(config('sentry.before_send'))->not->toBeInstanceOf(Closure::class);
});
