<?php

declare(strict_types=1);

use App\Http\Middleware\AddVaryAcceptHeader;
use Illuminate\Support\Facades\Route;
use Relaticle\Documentation\Http\Controllers\DocumentationController;
use Relaticle\Documentation\Http\Controllers\HelpController;
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
