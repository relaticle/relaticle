<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\Documentation\Http\Controllers\OpenApiSpecController;

Route::get('/openapi.json', [OpenApiSpecController::class, 'json'])->name('openapi.json');
Route::get('/openapi.yaml', [OpenApiSpecController::class, 'yaml'])->name('openapi.yaml');
