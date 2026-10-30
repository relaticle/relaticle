<?php

declare(strict_types=1);

use App\Http\Middleware\AddVaryAcceptHeader;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Relaticle\Cloud\Docs\Http\Controllers\DocumentationController;
use Relaticle\Cloud\Docs\Http\Controllers\HelpController;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

Route::middleware([ProvideMarkdownResponse::class, AddVaryAcceptHeader::class])->prefix('developers')->name('documentation.')->group(function (): void {
    Route::get('/', [DocumentationController::class, 'index'])->name('index');
    Route::get('/{type}', [DocumentationController::class, 'show'])->name('show');
});

Route::get('/llms.txt', [HelpController::class, 'llmsTxt'])->name('llms-txt');

Route::middleware([ProvideMarkdownResponse::class, AddVaryAcceptHeader::class])->prefix('help')->name('help.')->group(function (): void {
    Route::get('/', [HelpController::class, 'index'])->name('index');
    Route::get('/search-index.json', [HelpController::class, 'searchIndex'])->name('search-index');
    Route::get('/{category}', [HelpController::class, 'category'])->name('category')->where('category', '[a-z0-9-]+');
    Route::get('/{category}/{slug}', [HelpController::class, 'show'])->name('show')->where(['category' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+']);
});

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

    $isKnownTarget = $repository->find("docs/guides/{$slug}") instanceof DocPage
        || "/developers/{$slug}" === config('documentation.api_reference.url');

    return $isKnownTarget
        ? redirect("/developers/{$slug}", 301)
        : redirect('/developers', 301);
};

Route::get('/documentation/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
Route::get('/docs/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
