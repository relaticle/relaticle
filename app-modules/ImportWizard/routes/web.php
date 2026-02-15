<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\ImportWizard\Http\Controllers\DownloadFailedRowsController;

Route::get('imports/{import}/failed-rows/download', DownloadFailedRowsController::class)
    ->name('import-history.failed-rows.download')
    ->middleware('signed');
