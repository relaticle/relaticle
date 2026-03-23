<?php

declare(strict_types=1);
use Relaticle\EmailIntegration\Controllers\CallbackController as EmailCallbackController;
use Relaticle\EmailIntegration\Controllers\RedirectController as EmailRedirectController;

// Email Integration
Route::get('/email-accounts/redirect/{provider}', EmailRedirectController::class)
    ->name('email-accounts.redirect')
    ->middleware('throttle:10,1');

Route::get('/email-accounts/callback/{provider}', EmailCallbackController::class)
    ->name('email-accounts.callback')
    ->middleware('throttle:10,1');
