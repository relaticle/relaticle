<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Relaticle\EmailIntegration\Controllers\CalendarPushWebhookController;
use Relaticle\EmailIntegration\Controllers\CallbackController as EmailCallbackController;
use Relaticle\EmailIntegration\Controllers\InboundEmailWebhookController;
use Relaticle\EmailIntegration\Controllers\RedirectController as EmailRedirectController;

Route::middleware(['web'])->group(function (): void {
    Route::post('webhooks/calendar/{provider}', CalendarPushWebhookController::class)
        ->name('calendar-push.webhook')
        ->whereIn('provider', ['gmail', 'azure'])
        ->middleware('throttle:120,1');

    Route::post('webhooks/inbound/postmark/{token}', InboundEmailWebhookController::class)
        ->name('inbound-email.webhook')
        ->middleware('throttle:120,1');
});

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/email-accounts/redirect/{provider}', EmailRedirectController::class)
        ->name('email-accounts.redirect')
        ->whereIn('provider', ['gmail', 'azure'])
        ->middleware('throttle:10,1');

    Route::get('/email-accounts/callback/{provider}', EmailCallbackController::class)
        ->name('email-accounts.callback')
        ->whereIn('provider', ['gmail', 'azure'])
        ->middleware('throttle:10,1');
});
