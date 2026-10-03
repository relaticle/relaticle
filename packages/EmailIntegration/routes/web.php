<?php

declare(strict_types=1);

use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Relaticle\EmailIntegration\Controllers\CalendarPushWebhookController;
use Relaticle\EmailIntegration\Controllers\CallbackController as EmailCallbackController;
use Relaticle\EmailIntegration\Controllers\ComposerInlineImageController;
use Relaticle\EmailIntegration\Controllers\EmailAttachmentController;
use Relaticle\EmailIntegration\Controllers\RedirectController as EmailRedirectController;

Route::post('webhooks/calendar/{provider}', CalendarPushWebhookController::class)
    ->name('calendar-push.webhook')
    ->whereIn('provider', ['gmail', 'azure'])
    ->middleware('throttle:120,1');

Route::middleware(['web', 'auth', 'verified'])->group(function (): void {
    Route::get('/email-accounts/redirect/{provider}', EmailRedirectController::class)
        ->name('email-accounts.redirect')
        ->whereIn('provider', ['gmail', 'azure'])
        ->middleware(['signed', 'throttle:10,1']);

    Route::get('/email-accounts/callback/{provider}', EmailCallbackController::class)
        ->name('email-accounts.callback')
        ->whereIn('provider', ['gmail', 'azure'])
        ->middleware('throttle:10,1');
});

Route::middleware(['web', 'auth', 'verified', 'no-referrer', AuthenticateSession::class])->group(function (): void {
    Route::get('/email-compose-images', ComposerInlineImageController::class)
        ->name('email-compose-images.show');

    Route::get('/email-attachments/{attachment}', EmailAttachmentController::class)
        ->name('email-attachments.download');

    Route::get('/email-attachments/{attachment}/inline', EmailAttachmentController::class)
        ->name('email-attachments.inline');
});
