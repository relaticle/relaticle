<?php

declare(strict_types=1);

use Relaticle\Documentation\Support\DocUrl;

mutates(DocUrl::class);

/** @return array{lang: string, title: string, description: string} */
function headOf(string $html): array
{
    preg_match('/<html lang="([^"]*)">/', $html, $lang);
    preg_match('/<title>(.*?)<\/title>/s', $html, $title);
    preg_match('/<meta name="description" content="([^"]*)"/', $html, $description);

    return ['lang' => $lang[1] ?? '', 'title' => trim($title[1] ?? ''), 'description' => $description[1] ?? ''];
}

it('would regenerate the api reference with the head it serves today', function (): void {
    $served = $this->get('/developers/api')->assertOk()->getContent();

    $regenerated = view('scribe::external.scalar', [
        'metadata' => ['openapi_spec_url' => route('scribe.openapi'), 'title' => config('scribe.title')],
        'htmlAttributes' => [],
    ])->render();

    expect(headOf($regenerated))->toBe(headOf($served));
});

it('declares the api reference as english and its own canonical page', function (): void {
    $html = $this->get('/developers/api')->assertOk()->getContent();

    expect(headOf($html)['lang'])->toBe('en')
        ->and($html)->toContain('<link rel="canonical" href="'.route('scribe').'"/>');
});

it('links the docs and help center from the api reference through the documentation public url', function (): void {
    config()->set('documentation.public_url', 'https://docs.example.test');

    $html = $this->get('/developers/api')->assertOk()->getContent();

    expect($html)->toContain('<a href="https://docs.example.test/help">Help</a>')
        ->and($html)->toContain('<a href="https://docs.example.test/developers">Developers</a>')
        ->and($html)->toContain('<a href="https://docs.example.test/developers" class="relaticle-nav-title"')
        ->and($html)->not->toContain('href="/pricing"');
});

it('prefixes a localized help url by its registered route, whatever the install language is', function (): void {
    config()->set('app.default_locale', 'de');
    app()->setLocale('de');

    expect(DocUrl::area('help'))->toEndWith('/de/help');
})->skip(fn (): bool => ! class_exists('Relaticle\Cloud\CloudServiceProvider'), 'Localized help routes come from the cloud package.');
