<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\Documentation\Http\Controllers\DocumentationController;
use Relaticle\Documentation\Http\Controllers\HelpController;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

Route::middleware([ProvideMarkdownResponse::class])->prefix('docs')->name('documentation.')->group(function (): void {
    Route::get('/', [DocumentationController::class, 'index'])->name('index');
    Route::get('/search', [DocumentationController::class, 'search'])->name('search');
    Route::get('/{type}', [DocumentationController::class, 'show'])->name('show');
});

Route::middleware([ProvideMarkdownResponse::class])->prefix('help')->name('help.')->group(function (): void {
    Route::get('/', [HelpController::class, 'index'])->name('index');
    Route::get('/{category}', [HelpController::class, 'category'])->name('category')->where('category', '[a-z0-9-]+');
    Route::get('/{category}/{slug}', [HelpController::class, 'show'])->name('show')->where(['category' => '[a-z0-9-]+', 'slug' => '[a-z0-9-]+']);
});
