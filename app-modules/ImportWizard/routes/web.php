<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\ImportWizard\Http\Controllers\ImportCorrectionsController;
use Relaticle\ImportWizard\Http\Controllers\ImportValuesController;
use Relaticle\ImportWizard\Http\Controllers\PreviewController;

Route::middleware(['web', 'auth', 'verified'])
    ->prefix('app/import')
    ->name('import.')
    ->group(function (): void {
        Route::get('/{sessionId}/status', [PreviewController::class, 'status'])
            ->name('preview-status');
        Route::get('/{sessionId}/rows', [PreviewController::class, 'rows'])
            ->name('preview-rows');
        Route::post('/values', ImportValuesController::class)
            ->name('values');
        Route::post('/corrections', [ImportCorrectionsController::class, 'store'])
            ->name('corrections.store');
        Route::delete('/corrections', [ImportCorrectionsController::class, 'destroy'])
            ->name('corrections.destroy');
    });
