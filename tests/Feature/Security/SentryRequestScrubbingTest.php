<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\ServerRequest;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\Laravel\EventHandler;
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

    $laravelRequest = Request::create((string) $request->getUri(), $request->getMethod());
    $route = Arr::first(Route::getRoutes()->get($request->getMethod()), fn (Illuminate\Routing\Route $candidate): bool => $candidate->matches($laravelRequest));
    $laravelRequest->setRouteResolver(fn () => $route);

    app()->instance('request', $laravelRequest);
    app()->extend(ClientBuilder::class, fn (ClientBuilder $builder): ClientBuilder => $builder->setTransport($transport));
    app()->forgetInstance(HubInterface::class);
    app()->instance(LaravelRequestFetcher::CONTAINER_PSR7_INSTANCE_KEY, $request);

    $hub = resolve(HubInterface::class);
    SentrySdk::setCurrentHub($hub);

    return $hub;
}

/**
 * @param  array<string, string>  $body
 * @param  array<string, string>  $cookies
 */
function sentryRequest(string $method, string $uri, array $body = [], array $cookies = []): ServerRequest
{
    return new ServerRequest($method, 'https://relaticle.test'.$uri, [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Content-Length' => '120',
    ], null, '1.1', ['REMOTE_ADDR' => '203.0.113.9'])->withParsedBody($body)->withCookieParams($cookies);
}

/**
 * @param  array<string, string>  $body
 * @param  array<string, string>  $cookies
 */
function sentryReport(string $method, string $uri, array $body = [], array $cookies = [], ?Closure $tweak = null): Event
{
    $transport = new SentryTransportSpy;
    $request = sentryRequest($method, $uri, $body, $cookies);
    $request = $tweak instanceof Closure ? $tweak($request) : $request;

    sentryHubSending($transport, $request)->captureException(new RuntimeException('boom'));

    return $transport->events[0];
}

it('keeps request bodies out of error reports', function (): void {
    $event = sentryReport('POST', '/app/login', ['password' => 'hunter2-secret', 'code' => '123456']);

    expect($event->getRequest())->not->toHaveKey('data')
        ->and(json_encode($event->getRequest()))->not->toContain('hunter2-secret');
});

it('filters secret query values and keeps the rest', function (): void {
    $event = sentryReport('GET', '/api/v1/companies/01JABCDEFGHJKMNPQRSTVWXYZ0?token=tok-secret&signature=sig-secret&password=pw-secret&email=jo%40example.com&page=2');

    $request = $event->getRequest();

    expect($request['query_string'])->toContain('token=[Filtered]')
        ->and($request['query_string'])->toContain('signature=[Filtered]')
        ->and($request['query_string'])->toContain('password=[Filtered]')
        ->and($request['query_string'])->toContain('email=[Filtered]')
        ->and($request['query_string'])->toContain('page=2')
        ->and(json_encode($request))->not->toContain('tok-secret')
        ->and(json_encode($request))->not->toContain('sig-secret')
        ->and(json_encode($request))->not->toContain('pw-secret')
        ->and(json_encode($request))->not->toContain('example.com');
});

it('filters session, csrf and remember cookies', function (): void {
    $event = sentryReport('GET', '/app', cookies: [
        'relaticle_session' => 'session-secret',
        'XSRF-TOKEN' => 'xsrf-secret',
        'remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d' => 'remember-secret',
    ]);

    $json = json_encode($event->getRequest());

    expect($json)->not->toContain('session-secret')
        ->and($json)->not->toContain('xsrf-secret')
        ->and($json)->not->toContain('remember-secret')
        ->and($event->getRequest()['cookies']['relaticle_session'])->toBe('[Filtered]');
});

it('sends no user address or identity', function (): void {
    $event = sentryReport('GET', '/app', tweak: fn (ServerRequest $request): ServerRequest => $request
        ->withHeader('X-Forwarded-For', '198.51.100.7')
        ->withHeader('X-Real-IP', '198.51.100.7'));

    $json = json_encode([$event->getRequest(), $event->getUser()]);

    expect($event->getUser())->toBeNull()
        ->and($json)->not->toContain('203.0.113.9')
        ->and($json)->not->toContain('198.51.100.7');
});

it('masks secret path parameters in the url and keeps other parameters', function (string $method, string $path, string $expected, string $secret): void {
    $event = sentryReport($method, $path);

    expect($event->getRequest()['url'])->toBe('https://relaticle.test'.$expected)
        ->and(json_encode($event->getRequest()))->not->toContain($secret);
})->with([
    'invitation' => ['GET', '/invitations/iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii', '/invitations/{token}', 'iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii'],
    'invitation account switch' => ['POST', '/invitations/iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii/switch-account', '/invitations/{token}/switch-account', 'iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii'],
    'join link' => ['GET', '/join/jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj', '/join/{token}', 'jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj'],
    'email change' => ['GET', '/app/email-change-verification/verify/42/new%40example.com', '/app/email-change-verification/verify/42/{email}', 'new%40example.com'],
    'record ulid' => ['GET', '/api/v1/companies/01JABCDEFGHJKMNPQRSTVWXYZ0', '/api/v1/companies/01JABCDEFGHJKMNPQRSTVWXYZ0', '[Filtered]'],
]);

it('masks the path token of a referer and filters its query', function (string $referer, string $expected): void {
    $event = sentryReport('POST', '/livewire/update', tweak: fn (ServerRequest $request): ServerRequest => $request->withHeader('Referer', $referer));

    expect($event->getRequest()['headers']['Referer'])->toBe([$expected]);
})->with([
    'join' => ['https://relaticle.test/join/jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj?x=1', 'https://relaticle.test/join/{token}?x=1'],
    'invitation' => ['https://relaticle.test/invitations/iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii', 'https://relaticle.test/invitations/{token}'],
    'reset query' => ['https://relaticle.test/app/password-reset/reset?token=reset-secret', 'https://relaticle.test/app/password-reset/reset?token=[Filtered]'],
    'other host' => ['https://example.org/join/not-ours', 'https://example.org/join/not-ours'],
]);

it('masks the url and referer carried in the trace context', function (): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('GET', '/join/jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj'));

    $event = Event::createTransaction();
    $event->setContext('trace', ['data' => [
        'url' => '/join/jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj',
        'http.request.header.referer' => 'https://relaticle.test/invitations/iiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiiii',
    ], 'op' => 'http.server']);

    $hub->captureEvent($event);

    $data = $transport->events[0]->getContexts()['trace']['data'];

    expect($data['url'])->toBe('/join/{token}')
        ->and($data['http.request.header.referer'])->toBe('https://relaticle.test/invitations/{token}');
});

it('leaves reports outside an http request untouched', function (): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('GET', '/join/jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj'));
    app()->instance('request', Request::create('/'));

    $hub->captureException(new RuntimeException('boom'));

    expect($transport->events)->toHaveCount(1);
});

it('keeps sql bindings out of query breadcrumbs', function (): void {
    $transport = new SentryTransportSpy;
    $hub = sentryHubSending($transport, sentryRequest('GET', '/app'));

    $events = new Dispatcher;
    new EventHandler(app(), config('sentry'))->subscribe($events);
    $events->dispatch(new QueryExecuted('select ? as secret', ['binding-secret'], 1.0, DB::connection()));
    $hub->captureException(new RuntimeException('boom'));

    $breadcrumbs = array_map(fn (Breadcrumb $breadcrumb): array => [$breadcrumb->getMessage(), $breadcrumb->getMetadata()], $transport->events[0]->getBreadcrumbs());

    expect($breadcrumbs)->not->toBeEmpty()
        ->and(json_encode($breadcrumbs))->not->toContain('binding-secret');
});

it('opts into data collection with no bodies, no identity and no payloads', function (): void {
    $collection = config('sentry.data_collection');

    expect($collection)->toBeArray()
        ->and($collection['http_bodies'])->toBe([])
        ->and($collection['user_info'])->toBeFalse()
        ->and($collection['database_query_data'])->toBeFalse()
        ->and($collection['queues'])->toBeFalse()
        ->and($collection['stack_frame_variables'])->toBeFalse()
        ->and($collection['gen_ai'])->toBe(['inputs' => false, 'outputs' => false])
        ->and(config('sentry.before_send'))->toBeCallable()
        ->and(config('sentry.before_send'))->not->toBeInstanceOf(Closure::class)
        ->and(config('sentry.before_send_transaction'))->toBeCallable();
});
