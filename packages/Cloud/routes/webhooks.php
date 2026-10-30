<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Middleware\VerifyWebhookSignature;
use Relaticle\Cloud\Billing\Http\StripeWebhookController;

// Registered here, not by Cashier, so signature verification never depends on the webhook secret.
// Outside the web group on purpose: Stripe sends no CSRF token and signs every request instead.
Route::post('stripe/webhook', [StripeWebhookController::class, 'handleWebhook'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('cashier.webhook');
