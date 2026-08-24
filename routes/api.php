<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\CompaniesController;
use App\Http\Controllers\Api\V1\CompaniesUpsertController;
use App\Http\Controllers\Api\V1\CustomFieldsController;
use App\Http\Controllers\Api\V1\NotesController;
use App\Http\Controllers\Api\V1\OpportunitiesController;
use App\Http\Controllers\Api\V1\PeopleController;
use App\Http\Controllers\Api\V1\PeopleUpsertController;
use App\Http\Controllers\Api\V1\TasksController;
use App\Http\Middleware\EnsureHostedWorkspaceAccess;
use App\Http\Middleware\EnsureTokenHasAbility;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\SetApiTeamContext;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([ForceJsonResponse::class, 'auth:sanctum,api', 'throttle:api', EnsureTokenHasAbility::class, SetApiTeamContext::class, EnsureHostedWorkspaceAccess::class])
    ->group(function (): void {
        Route::get('user', function (Request $request) {
            return new UserResource($request->user());
        });

        // Declared ahead of the resource routes so the literal segment is never
        // read as a record key, and gated on both abilities because the endpoint
        // may create or update.
        Route::post('companies/upsert', CompaniesUpsertController::class)
            ->middleware(EnsureTokenHasAbility::class.':create,update')
            ->name('companies.upsert');

        Route::post('people/upsert', PeopleUpsertController::class)
            ->middleware(EnsureTokenHasAbility::class.':create,update')
            ->name('people.upsert');

        Route::apiResource('companies', CompaniesController::class);
        Route::apiResource('people', PeopleController::class);
        Route::apiResource('opportunities', OpportunitiesController::class);
        Route::apiResource('tasks', TasksController::class);
        Route::apiResource('notes', NotesController::class);

        Route::get('custom-fields', [CustomFieldsController::class, 'index'])->name('custom-fields.index');
    });
