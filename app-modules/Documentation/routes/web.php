<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\Documentation\Http\Controllers\DocumentationController;

Route::prefix('docs')->name('documentation.')->group(function (): void {
    Route::get('/', [DocumentationController::class, 'index'])->name('index');
    Route::get('/search', [DocumentationController::class, 'search'])->name('search');
    Route::get('/{type}', [DocumentationController::class, 'show'])->name('show');
});
