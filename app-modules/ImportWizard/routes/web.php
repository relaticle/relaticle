<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\ImportWizard\Http\Controllers\PreviewController;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('app/import')
    ->name('import.')
    ->group(function (): void {
        Route::get('/{sessionId}/status', [PreviewController::class, 'status'])
            ->name('preview-status');
        Route::get('/{sessionId}/rows', [PreviewController::class, 'rows'])
            ->name('preview-rows');
    });
