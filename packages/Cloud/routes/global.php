<?php

declare(strict_types=1);

use App\Features\Documentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Docs\Http\Controllers\HelpController;
use Relaticle\Cloud\Marketing\Support\LocalizedUrl;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;

Route::get('/sitemap.xml', function (): Response {
    $sitemap = Storage::disk('public')->get('sitemap.xml');

    abort_if($sitemap === null, Response::HTTP_NOT_FOUND);

    return response($sitemap, Response::HTTP_OK, [
        'Content-Type' => 'application/xml; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('sitemap');

Route::get('/discord', fn (): RedirectResponse => redirect()->away(config('services.discord.invite_url')))->name('discord');

if (Feature::for(null)->active(Documentation::class)) {
    Route::get('/llms.txt', [HelpController::class, 'llmsTxt'])->name('llms-txt');

    // Indexed /documentation/* and /docs/* URLs map straight to the final URL, so no chain exceeds one hop.
    $legacyDocsRedirect = function (DocsRepository $repository, string $slug = ''): RedirectResponse {
        $map = [
            'quickstart' => '/help/getting-started',
            'getting-started' => '/help/getting-started',
            'import' => '/help/import',
            'developer' => '/developers/contributing',
        ];

        if (isset($map[$slug])) {
            return redirect($map[$slug], 301);
        }

        $isKnownTarget = $repository->find("docs/guides/{$slug}", LocalizedUrl::DEFAULT_LOCALE) instanceof DocPage
            || "/developers/{$slug}" === config('documentation.api_reference.url');

        return $isKnownTarget
            ? redirect("/developers/{$slug}", 301)
            : redirect('/developers', 301);
    };

    Route::get('/documentation/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
    Route::get('/docs/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
}
