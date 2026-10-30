<?php

declare(strict_types=1);

use App\Support\DetectsPublicMarkdownRequest;
use Illuminate\Support\Facades\Route;
use Relaticle\Documentation\Support\DocUrl;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

mutates(DetectsPublicMarkdownRequest::class, DocUrl::class);

it('converts a real html table to pipe-table markdown', function (): void {
    Route::middleware(ProvideMarkdownResponse::class)->get('/__markdown-table-fixture', fn () => response(<<<'HTML'
        <html>
        <body>
        <main>
            <table>
                <thead>
                    <tr><th>Plan</th><th>Price</th></tr>
                </thead>
                <tbody>
                    <tr><td>Free</td><td>$0</td></tr>
                    <tr><td>Pro</td><td>$29</td></tr>
                </tbody>
            </table>
        </main>
        </body>
        </html>
        HTML));

    $markdown = $this->get('/__markdown-table-fixture', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->getContent();

    expect($markdown)->toContain('| Plan | Price |')
        ->and($markdown)->toContain('| Free | $0 |')
        ->and($markdown)->toContain('| Pro | $29 |');
});

it('gives agents a markdown 404 that links the indexes', function (array $headers, string $url): void {
    $this->get($url, $headers)
        ->assertNotFound()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8')
        ->assertSee('# Not found', false)
        ->assertSee(DocUrl::root().'/llms.txt', false)
        ->assertSee(DocUrl::area(DocUrl::HELP), false)
        ->assertSee(DocUrl::area(DocUrl::DOCS), false)
        ->assertSee(route('openapi.json'), false);
})->with([
    'markdown accept header' => [['Accept' => 'text/markdown'], '/no-such-page'],
    'ai user agent' => [['Accept' => '*/*', 'User-Agent' => 'Mozilla/5.0 (compatible; ClaudeBot/1.0)'], '/no-such-page'],
    'md suffix' => [['Accept' => '*/*'], '/no-such-page.md'],
]);

it('keeps the html 404 for browsers, including in-app wire:navigate fetches', function (array $headers): void {
    $this->get('/no-such-page', $headers)
        ->assertNotFound()
        ->assertHeader('content-type', 'text/html; charset=UTF-8');
})->with([
    'page load' => [['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']],
    'wire:navigate' => [['Accept' => '*/*', 'X-Livewire-Navigate' => '1']],
    'generic client' => [['Accept' => '*/*']],
]);

it('keeps the json 404 for the api', function (): void {
    $this->getJson('/api/v1/no-such-endpoint')
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');

    $this->get('/api/v1/no-such-endpoint', ['Accept' => 'text/markdown'])
        ->assertNotFound()
        ->assertHeader('content-type', 'application/json');
});

it('echoes the missing url as inert code in the markdown 404', function (string $path): void {
    $body = $this->get($path, ['Accept' => 'text/markdown'])->assertNotFound()->getContent();

    expect($body)->toMatch('/^Nothing lives at `[^`\n]+`\.$/m');
})->with([
    'markdown link' => ['/x[login](https://evil.example)'],
    'backtick breakout' => ['/x`[login](https://evil.example)`'],
]);

it('builds the markdown 404 index links from the documentation public url', function (): void {
    config()->set('documentation.public_url', 'https://docs.example.test');

    $this->get('/no-such-page', ['Accept' => 'text/markdown'])
        ->assertNotFound()
        ->assertSee('- Site index: https://docs.example.test/llms.txt', false)
        ->assertSee('- Help centre: https://docs.example.test/help', false)
        ->assertSee('- Developer docs: https://docs.example.test/developers', false);
});
