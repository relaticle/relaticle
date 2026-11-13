<?php

declare(strict_types=1);

use App\Http\Middleware\ValidateSignature;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

mutates(ValidateSignature::class);

it('runs the signed route once when it throws an invalid signature exception itself', function (): void {
    $runs = 0;

    Route::get('/_signed-probe', function () use (&$runs): never {
        $runs++;

        throw new InvalidSignatureException;
    })->middleware('signed')->name('signed-probe');

    $routes = new RouteCollection;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $routes->add($route);
    }

    Route::setRoutes($routes);

    $this->get(URL::signedRoute('signed-probe'))->assertForbidden();

    expect($runs)->toBe(1);
});

it('rejects an array signature parameter with 403', function (): void {
    Route::get('/_signed-array-probe', fn (): string => 'ok')->middleware('signed')->name('signed-array-probe');

    $routes = new RouteCollection;

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $routes->add($route);
    }

    Route::setRoutes($routes);

    $this->get(URL::signedRoute('signed-array-probe').'&signature[]=x')->assertForbidden();
    $this->get('/_signed-array-probe?signature[]=x')->assertForbidden();
});
