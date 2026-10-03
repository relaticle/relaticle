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
use App\Http\Middleware\SetApiWorkspaceContext;
use App\Http\Middleware\SetCurrentSource;
use App\Http\Resources\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->middleware([ForceJsonResponse::class, 'auth:sanctum,api', 'throttle:api', EnsureTokenHasAbility::class, SetCurrentSource::class.':api', SetApiWorkspaceContext::class, EnsureHostedWorkspaceAccess::class])
    ->group(function (): void {
        Route::get('user', function (Request $request) {
            return new UserResource($request->user());
        });

        // Registered before the resource routes so `upsert` is never read as a record key.
        Route::post('companies/upsert', CompaniesUpsertController::class)
            ->middleware(EnsureTokenHasAbility::class.':create,update')
            ->name('companies.upsert');

        Route::post('people/upsert', PeopleUpsertController::class)
            ->middleware(EnsureTokenHasAbility::class.':create,update')
            ->name('people.upsert');

        foreach (['companies' => CompaniesController::class, 'people' => PeopleController::class, 'opportunities' => OpportunitiesController::class, 'tasks' => TasksController::class, 'notes' => NotesController::class] as $resource => $controller) {
            Route::post("{$resource}/query", [$controller, 'index'])->name("{$resource}.query");
        }

        Route::apiResource('companies', CompaniesController::class);
        Route::apiResource('people', PeopleController::class);
        Route::apiResource('opportunities', OpportunitiesController::class);
        Route::apiResource('tasks', TasksController::class);
        Route::apiResource('notes', NotesController::class);

        Route::get('custom-fields', [CustomFieldsController::class, 'index'])->name('custom-fields.index');
    });
