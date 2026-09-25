<?php

declare(strict_types=1);

/** @return array{title: string, description: string} */
function headOf(string $html): array
{
    preg_match('/<title>(.*?)<\/title>/s', $html, $title);
    preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);

    return ['title' => trim($title[1] ?? ''), 'description' => $description[1] ?? ''];
}

it('serves every current developer docs url', function (string $path): void {
    $this->get($path)->assertOk();
})->with([
    '/developers',
    '/developers/self-hosting',
    '/developers/mcp',
    '/developers/contributing',
    '/developers/api',
]);

it('would regenerate the api reference with the head it serves today', function (): void {
    $served = $this->get('/developers/api')->assertOk()->getContent();

    $regenerated = view('scribe::external.scalar', [
        'metadata' => ['openapi_spec_url' => route('scribe.openapi'), 'title' => config('scribe.title')],
        'htmlAttributes' => [],
    ])->render();

    expect(headOf($regenerated))->toBe(headOf($served));
});

it('permanently redirects every url google indexed before the /developers rename', function (string $indexed): void {
    // These exact URLs were live and indexed (some twice -- first as
    // /documentation/*, then as /docs/*). They must never 404 or chain.
    $response = $this->get($indexed);

    $response->assertStatus(301);

    $target = $response->headers->get('Location');

    $this->get($target)->assertOk();
})->with([
    '/docs',
    '/docs/getting-started',
    '/docs/import',
    '/docs/developer',
    '/docs/self-hosting',
    '/docs/mcp',
    '/docs/api',
]);
