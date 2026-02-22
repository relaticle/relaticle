<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CompaniesController;
use App\Http\Controllers\Api\V1\CustomFieldsController;
use App\Http\Controllers\Api\V1\NotesController;
use App\Http\Controllers\Api\V1\OpportunitiesController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\TasksController;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetApiTeamContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:api', ForceJsonResponse::class, SetApiTeamContext::class])
    ->group(function (): void {
        Route::apiResource('companies', CompaniesController::class);
        Route::apiResource('people', PeopleController::class);
        Route::apiResource('opportunities', OpportunitiesController::class);
        Route::apiResource('tasks', TasksController::class);
        Route::apiResource('notes', NotesController::class);

        Route::get('custom-fields', [CustomFieldsController::class, 'index']);
    });
