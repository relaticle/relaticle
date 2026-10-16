<?php

declare(strict_types=1);

use App\Http\Middleware\SecurityHeaders;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\UploadFileTool;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(SecurityHeaders::class);

it('sets framing and sniffing headers on the login page', function (): void {
    $this->get('/app/login')
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});

it('denies framing on the rendered oauth consent screen', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'Test MCP Client',
        'redirect_uris' => ['https://example.com/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    $this->actingAs($user)
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://example.com/callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'state' => 'test-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
        ]))
        ->assertOk()
        ->assertSee('Test MCP Client')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

function secureLoginUrl(): string
{
    return 'https://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/app/login';
}

function asEnvironment(string $environment): void
{
    app()->detectEnvironment(fn (): string => $environment);
}

afterEach(function (): void {
    Request::setTrustedHosts([]);
});

it('sends HSTS without subdomains on a secure production request', function (): void {
    asEnvironment('production');

    $this->get(secureLoginUrl())
        ->assertHeader('Strict-Transport-Security', 'max-age=15552000');
});

it('adds includeSubDomains only when opted in', function (): void {
    asEnvironment('production');
    config()->set('app.hsts.include_subdomains', true);
    config()->set('app.hsts.max_age', 31536000);

    $this->get(secureLoginUrl())
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('sends no HSTS on plain HTTP in production', function (): void {
    asEnvironment('production');

    $this->get('http://'.parse_url((string) config('app.url'), PHP_URL_HOST).'/app/login')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends no HSTS outside production', function (string $environment): void {
    asEnvironment($environment);

    $this->get(secureLoginUrl())
        ->assertHeaderMissing('Strict-Transport-Security');
})->with(['local', 'staging', 'testing']);

it('keeps the headers a response already carries', function (): void {
    Route::middleware('web')->get('/_test/own-headers', fn () => response('ok', 200, [
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'no-referrer',
    ]));

    $this->get('/_test/own-headers')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'no-referrer')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('leaves the media response content security policy intact', function (): void {
    config()->set('app.csp.mode', 'report-only');
    Storage::fake('local');
    $user = User::factory()->withPersonalWorkspace()->create();

    RelaticleServer::actingAs($user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'pixel.png'])
        ->assertOk();
    $media = Media::query()->latest('id')->firstOrFail();

    $this->get($media->getUrl())
        ->assertOk()
        ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox")
        ->assertHeaderMissing('Content-Security-Policy-Report-Only')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY');
});

function consentScreenUrl(User $user): string
{
    $client = Client::query()->forceCreate([
        'id' => (string) Str::uuid(),
        'name' => 'Test MCP Client',
        'redirect_uris' => ['http://localhost:33418/callback'],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'revoked' => false,
        'owner_type' => $user->getMorphClass(),
        'owner_id' => $user->getKey(),
    ]);

    return '/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'http://localhost:33418/callback',
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'test-state',
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
    ]);
}

it('enforces the full content security policy in enforce mode', function (): void {
    config()->set('app.csp.mode', 'enforce');

    $response = $this->get('/app/login')->assertHeaderMissing('Content-Security-Policy-Report-Only');

    expect($response->headers->get('Content-Security-Policy'))
        ->toContain("default-src 'self'")
        ->toContain("frame-ancestors 'none'")
        ->toContain("object-src 'none'")
        ->toContain("base-uri 'self'");
});

it('allows remote https images so an email body can show its own', function (): void {
    config()->set('app.csp.mode', 'enforce');

    $policy = (string) $this->get('/app/login')->headers->get('Content-Security-Policy');

    expect($policy)->toMatch('/img-src [^;]*\\bhttps:(?= |;)/')
        ->and($policy)->not->toMatch('/(script|connect|frame)-src [^;]*\\bhttps:(?= |;)/');
});

it('enforces frame-ancestors on the login page and the consent screen in every mode', function (string $mode): void {
    config()->set('app.csp.mode', $mode);
    $user = User::factory()->withPersonalWorkspace()->create();
    $consentUrl = consentScreenUrl($user);

    $responses = [
        $this->get('/app/login')->assertOk(),
        $this->actingAs($user)->get($consentUrl)->assertOk(),
    ];

    foreach ($responses as $response) {
        expect($response->headers->get('Content-Security-Policy'))
            ->toContain("frame-ancestors 'none'")
            ->toContain("object-src 'none'")
            ->toContain("base-uri 'self'");
    }
})->with(['enforce', 'report-only', 'off']);

it('sends the full policy as report-only and enforces only the safe directives in report-only mode', function (): void {
    config()->set('app.csp.mode', 'report-only');

    $response = $this->get('/app/login');

    expect($response->headers->get('Content-Security-Policy'))
        ->toBe("frame-ancestors 'none'; object-src 'none'; base-uri 'self'")
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))
        ->toContain("default-src 'self'")
        ->toContain("script-src 'self'")
        ->toContain("frame-ancestors 'none'");
});

it('enforces only the safe directives when the policy is off', function (): void {
    config()->set('app.csp.mode', 'off');

    $this->get('/app/login')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'none'; object-src 'none'; base-uri 'self'")
        ->assertHeaderMissing('Content-Security-Policy-Report-Only');
});

it('allows the websocket origin the panel hands to Echo', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filament.broadcasting.echo', ['wsHost' => 'ws.example.com', 'wsPort' => 8443, 'forceTLS' => true]);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain("connect-src 'self' https://cdn.usefathom.com wss://ws.example.com:8443");
});

it('allows no websocket origin when broadcasting is off', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filament.broadcasting', []);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->not->toContain('ws://')
        ->not->toContain('wss://');
});

it('fails closed to the full policy for an empty or unknown mode', function (string $mode): void {
    config()->set('app.csp.mode', $mode);

    $response = $this->get('/app/login');

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'");
})->with(['', 'report_only', 'ENFORCE ', 'nonsense']);

it('adds no storage origin while every disk is local', function (): void {
    config()->set('app.csp.mode', 'enforce');

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain("img-src 'self' data: blob: https:;")
        ->toContain("connect-src 'self' https://cdn.usefathom.com;")
        ->toContain("media-src 'self' blob:;");
});

it('allows the public disk url and the presigned bucket origin for a virtual-hosted s3 disk', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filesystems.disks.public.url', 'https://files.example-cdn.com/assets');
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'bucket' => 'crm-bucket',
        'url' => 'https://crm-bucket.cdn.example.com',
        'endpoint' => 'https://objects.example.com',
        'region' => 'auto',
        'use_path_style_endpoint' => false,
    ]);

    $csp = $this->get('/app/login')->headers->get('Content-Security-Policy');

    foreach (['img-src', 'connect-src', 'media-src'] as $directive) {
        preg_match("/{$directive} ([^;]+);/", (string) $csp, $matches);

        expect($matches[1])
            ->toContain('https://files.example-cdn.com')
            ->toContain('https://crm-bucket.cdn.example.com')
            ->toContain('https://crm-bucket.objects.example.com')
            ->not->toContain('https://objects.example.com');
    }
});

it('uses the endpoint origin itself for a path-style s3 disk', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'bucket' => 'crm-bucket',
        'endpoint' => 'http://minio.internal:9000',
        'use_path_style_endpoint' => true,
    ]);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain("connect-src 'self' https://cdn.usefathom.com http://minio.internal:9000;");
});

it('allows both the legacy global host and the regional host for an aws us-east-1 bucket', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filesystems.disks.s3', ['driver' => 's3', 'bucket' => 'crm-bucket', 'region' => 'us-east-1']);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain('https://crm-bucket.s3.amazonaws.com')
        ->toContain('https://crm-bucket.s3.us-east-1.amazonaws.com');
});

it('allows the regional host only for an aws bucket outside us-east-1', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filesystems.disks.s3', ['driver' => 's3', 'bucket' => 'crm-bucket', 'region' => 'eu-west-1']);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain('https://crm-bucket.s3.eu-west-1.amazonaws.com')
        ->not->toContain('https://crm-bucket.s3.amazonaws.com');
});

it('allows the temporary url host an s3 disk rewrites presigned urls to', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'bucket' => 'crm-bucket',
        'endpoint' => 'http://minio:9000',
        'use_path_style_endpoint' => true,
        'temporary_url' => 'https://uploads.example.com',
    ]);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain('https://uploads.example.com');
});

it('allows a bucket on the app host when its port differs', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('app.url', 'https://crm.example.com');
    config()->set('filesystems.disks.s3', [
        'driver' => 's3',
        'bucket' => 'crm-bucket',
        'endpoint' => 'https://crm.example.com:9000',
        'use_path_style_endpoint' => true,
    ]);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain('https://crm.example.com:9000');
});

it('ignores an s3 disk without a bucket', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('filesystems.disks.s3', ['driver' => 's3', 'endpoint' => 'https://objects.example.com']);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))->not->toContain('objects.example.com');
});

it('allows a foreign asset url origin for scripts, styles, fonts and images', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('app.asset_url', 'https://assets.example-cdn.com/build');

    $csp = (string) $this->get('/app/login')->headers->get('Content-Security-Policy');

    foreach (['script-src', 'style-src', 'font-src', 'img-src'] as $directive) {
        preg_match("/{$directive} ([^;]+);/", $csp, $matches);

        expect($matches[1])->toContain('https://assets.example-cdn.com');
    }

    preg_match('/connect-src ([^;]+);/', $csp, $matches);

    expect($matches[1])->not->toContain('assets.example-cdn.com');
});

it('allows the bunny font host on the horizon dashboard only', function (): void {
    config()->set('app.csp.mode', 'enforce');
    Route::middleware('web')->get('/horizon/_test', fn () => '<html></html>');

    $horizon = $this->get('/horizon/_test')->headers->get('Content-Security-Policy');
    $login = $this->get('/app/login')->headers->get('Content-Security-Policy');

    expect($horizon)
        ->toContain('style-src \'self\' \'unsafe-inline\' https://fonts.bunny.net')
        ->toContain("font-src 'self' data: https://fonts.bunny.net")
        ->and($login)
        ->not->toContain('bunny');
});

it('adds the configured extra sources to a directive', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('app.csp.extra.img-src', ['https://cdn.example.com']);

    expect($this->get('/app/login')->headers->get('Content-Security-Policy'))
        ->toContain("img-src 'self' data: blob: https: https://cdn.example.com");
});

it('does not restrict form-action so the oauth consent redirect can reach the client', function (): void {
    config()->set('app.csp.mode', 'enforce');
    $user = User::factory()->withPersonalWorkspace()->create();

    expect($this->actingAs($user)->get(consentScreenUrl($user))->headers->get('Content-Security-Policy'))
        ->not->toContain('form-action');
});

it('adds no content security policy to a json response', function (): void {
    config()->set('app.csp.mode', 'enforce');

    $this->getJson('/up')->assertHeaderMissing('Content-Security-Policy');
});

it('allows the scalar cdn and fonts on the api reference only', function (): void {
    config()->set('app.csp.mode', 'enforce');

    $docs = $this->get('/developers/api')->headers->get('Content-Security-Policy');
    $login = $this->get('/app/login')->headers->get('Content-Security-Policy');

    expect($docs)
        ->toContain('https://cdn.jsdelivr.net')
        ->toContain('https://fonts.scalar.com')
        ->and($login)
        ->not->toContain('cdn.jsdelivr.net')
        ->not->toContain('fonts.scalar.com');
});

it('lets the api reference send test requests to a dedicated api host', function (): void {
    config()->set('app.csp.mode', 'enforce');
    config()->set('scribe.base_url', 'https://api.example.com');

    $docs = $this->get('/developers/api')->headers->get('Content-Security-Policy');
    $login = $this->get('/app/login')->headers->get('Content-Security-Policy');

    expect($docs)
        ->toContain("connect-src 'self' https://cdn.usefathom.com https://api.example.com")
        ->and($login)
        ->not->toContain('api.example.com');
});

it('allows the vite dev server only while it runs hot', function (): void {
    config()->set('app.csp.mode', 'enforce');
    $this->withVite();
    $hotFile = tempnam(sys_get_temp_dir(), 'hot');
    file_put_contents($hotFile, 'https://sucre.test:5173');
    Vite::useHotFile($hotFile);

    $hot = $this->get('/app/login')->headers->get('Content-Security-Policy');
    unlink($hotFile);
    $cold = $this->get('/app/login')->headers->get('Content-Security-Policy');

    expect($hot)
        ->toContain("script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.usefathom.com https://challenges.cloudflare.com https://sucre.test:5173")
        ->toContain('wss://sucre.test:5173')
        ->and($cold)
        ->not->toContain(':5173');
});
