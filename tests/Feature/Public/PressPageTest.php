<?php

declare(strict_types=1);

use App\Support\CompetitorFacts;
use Dom\HTMLDocument;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

mutates(CompetitorFacts::class);

beforeEach(function (): void {
    Http::fake(['api.github.com/*' => Http::response(['stargazers_count' => 42])]);
});

it('renders the press page with facts and unique metadata', function (): void {
    $this->get('/press')
        ->assertOk()
        ->assertSee('AGPL-3.0')
        ->assertSee(CompetitorFacts::mcpToolCount().' MCP tools')
        ->assertSee('<title>'.e(__('Press kit & brand assets')).' - Relaticle</title>', false);
});

it('shows the live GitHub star count', function (): void {
    Cache::put('github_stars_Relaticle_relaticle', 1_734, 60);

    $this->get('/press')
        ->assertOk()
        ->assertSee('1,734 stars')
        ->assertDontSee('stars as of');
});

it('falls back to the dated star count without a live count', function (): void {
    Cache::put('github_stars_Relaticle_relaticle', 0, 60);

    $facts = CompetitorFacts::all()['relaticle'];

    $this->get('/press')
        ->assertOk()
        ->assertSee(number_format($facts['stars']).' stars as of '.Date::parse($facts['stars_verified'])->format('F j, Y'));
});

it('serves the press page as markdown', function (): void {
    $this->get('/press', ['Accept' => 'text/markdown'])
        ->assertOk()
        ->assertHeader('content-type', 'text/markdown; charset=UTF-8')
        ->assertSee('brand/kit.zip')
        ->assertSee('brand/kit/logos/lockup-color.svg')
        ->assertSee('images/app-pipeline-preview-dark.png');
});

it('offers existing nonempty files for every press download', function (): void {
    $response = $this->get('/press')->assertOk();
    $document = HTMLDocument::createFromString((string) $response->getContent(), LIBXML_NOERROR);
    $downloads = $document->querySelectorAll('main a[download]');

    expect($downloads->length)->toBeGreaterThan(30);

    foreach ($downloads as $download) {
        $path = public_path(ltrim((string) parse_url($download->getAttribute('href'), PHP_URL_PATH), '/'));

        expect($path)->toBeFile();
        expect(filesize($path))->toBeGreaterThan(0);
    }
});

it('ships a complete brand archive matching the published assets and original artwork', function (): void {
    $this->get('/press')->assertSee(asset('brand/kit.zip'));
    $manifest = json_decode(file_get_contents(public_path('brand/kit/manifest.json')), true, flags: JSON_THROW_ON_ERROR);
    $archive = new ZipArchive;

    expect($archive->open(public_path('brand/kit.zip'), ZipArchive::CHECKCONS))->toBeTrue();

    try {
        expect($archive->numFiles)->toBe(count($manifest['files']) + 1);
        expect($archive->getFromName('relaticle-brand-kit/manifest.json'))
            ->toBe(file_get_contents(public_path('brand/kit/manifest.json')));

        foreach ($manifest['sources'] as $file => $checksum) {
            expect(hash_file('sha256', public_path('brand/'.$file)))->toBe($checksum);
        }

        foreach ($manifest['files'] as $file => $checksum) {
            expect(hash_file('sha256', public_path('brand/kit/'.$file)))->toBe($checksum);
            expect(hash('sha256', $archive->getFromName('relaticle-brand-kit/'.$file)))->toBe($checksum);
        }

        foreach ($manifest['platforms'] as $platform) {
            foreach ($platform['files'] as $file) {
                [$width, $height, $type] = getimagesize(public_path('brand/kit/'.$file));

                expect([$width, $height, $type])->toBe([$platform['size'], $platform['size'], IMAGETYPE_PNG]);
            }
        }
    } finally {
        $archive->close();
    }
});
