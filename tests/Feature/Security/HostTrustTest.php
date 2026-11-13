<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationType;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

function withProdHostTrust(): void
{
    config()->set('app.url', 'https://crm.example.com');
    app()->detectEnvironment(fn () => 'production');
}

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('ignores a forged X-Forwarded-Host when building URLs', function () {
    withProdHostTrust();

    $response = $this->withHeaders(['X-Forwarded-Host' => 'evil.test'])
        ->get('https://crm.example.com/app/login');

    $response->assertOk()
        ->assertSee('https://crm.example.com/livewire-', false)
        ->assertDontSee('evil.test', false);
});

it('admits the configured application host', function () {
    withProdHostTrust();

    $this->get('https://crm.example.com/up')->assertSuccessful();
});

it('rejects a request whose Host is not an allowed host', function () {
    withProdHostTrust();

    $this->get('https://evil.test/app/login')->assertStatus(400);
});

it('admits a host listed in TRUSTED_HOSTS', function () {
    withProdHostTrust();
    config()->set('app.trusted_hosts', ['health.internal']);

    $this->get('https://health.internal/up')->assertSuccessful();
});

it('does not validate a signed URL replayed under a different host', function () {
    $user = User::factory()->create();
    withProdHostTrust();
    config()->set('app.trusted_hosts', ['health.internal']);

    URL::forceRootUrl('https://crm.example.com');
    URL::forceScheme('https');

    $signedUrl = URL::signedRoute('mail.unsubscribe', [
        'user' => $user,
        'type' => NotificationType::TaskDigest->value,
    ]);

    expect($signedUrl)->toStartWith('https://crm.example.com/');

    $this->get($signedUrl)->assertOk();
    $this->get(str_replace('crm.example.com', 'health.internal', $signedUrl))->assertForbidden();
});

function clientIpBehindProxy(string $remoteAddr, string $forwardedFor = '203.0.113.5'): string
{
    Route::get('/_proxy-ip-probe', fn (Request $request): string => (string) $request->ip());

    return test()->call('GET', '/_proxy-ip-probe', server: [
        'REMOTE_ADDR' => $remoteAddr,
        'HTTP_X_FORWARDED_FOR' => $forwardedFor,
    ])->assertOk()->getContent();
}

it('trusts the private ranges when TRUSTED_PROXIES is unset', function () {
    config()->set('app.trusted_proxies', []);

    expect(clientIpBehindProxy('10.0.0.5'))->toBe('203.0.113.5');
});

it('trusts only the proxies listed in TRUSTED_PROXIES', function () {
    config()->set('app.trusted_proxies', ['192.168.50.2']);

    expect(clientIpBehindProxy('10.0.0.5'))->toBe('10.0.0.5');
    expect(clientIpBehindProxy('192.168.50.2'))->toBe('203.0.113.5');
});

it('trims and drops empty TRUSTED_PROXIES entries', function () {
    $previous = [$_SERVER['TRUSTED_PROXIES'] ?? null, $_ENV['TRUSTED_PROXIES'] ?? null, getenv('TRUSTED_PROXIES')];
    $value = ' 192.168.50.2 , ,10.9.0.0/16,';

    $_SERVER['TRUSTED_PROXIES'] = $_ENV['TRUSTED_PROXIES'] = $value;
    putenv("TRUSTED_PROXIES={$value}");

    try {
        $parsed = (require config_path('app.php'))['trusted_proxies'];
    } finally {
        foreach (['_SERVER' => $previous[0], '_ENV' => $previous[1]] as $bag => $old) {
            if ($old === null) {
                unset($GLOBALS[$bag]['TRUSTED_PROXIES']);
            } else {
                $GLOBALS[$bag]['TRUSTED_PROXIES'] = $old;
            }
        }

        putenv($previous[2] === false ? 'TRUSTED_PROXIES' : "TRUSTED_PROXIES={$previous[2]}");
    }

    expect($parsed)->toBe(['192.168.50.2', '10.9.0.0/16']);
});

it('trusts every sender when TRUSTED_PROXIES lists a wildcard among other entries', function () {
    config()->set('app.trusted_proxies', ['*', '10.0.0.1']);

    expect(clientIpBehindProxy('203.0.113.99', '198.51.100.7'))->toBe('198.51.100.7');
});
