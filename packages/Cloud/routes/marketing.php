<?php

declare(strict_types=1);

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Relaticle\Cloud\Marketing\Enums\ConnectableAssistant;
use Relaticle\Cloud\Marketing\Http\Controllers\AlternativesController;
use Relaticle\Cloud\Marketing\Http\Controllers\AssistantPagesController;
use Relaticle\Cloud\Marketing\Http\Controllers\ComparisonController;
use Relaticle\Cloud\Marketing\Http\Controllers\ContactController;
use Relaticle\Cloud\Marketing\Http\Controllers\HomeController;
use Relaticle\Cloud\Marketing\Http\Controllers\PrivacyPolicyController;
use Relaticle\Cloud\Marketing\Http\Controllers\TermsOfServiceController;
use Relaticle\Cloud\Marketing\Http\Middleware\AddVaryAcceptHeader;
use Spatie\Honeypot\ProtectAgainstSpam;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

Route::get('/sitemap.xml', function (): Response {
    $sitemap = Storage::disk('public')->get('sitemap.xml');

    abort_if($sitemap === null, Response::HTTP_NOT_FOUND);

    return response($sitemap, Response::HTTP_OK, [
        'Content-Type' => 'application/xml; charset=UTF-8',
        'Cache-Control' => 'public, max-age=3600',
    ]);
})->name('sitemap');

Route::middleware([ProvideMarkdownResponse::class, AddVaryAcceptHeader::class])->group(function (): void {
    Route::get('/', HomeController::class);
    Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms.show');
    Route::get('/privacy-policy', PrivacyPolicyController::class)->name('policy.show');
    Route::get('/pricing', fn (): View => view('cloud::marketing.pricing'))->name('pricing');
    Route::get('/press', fn (): View => view('cloud::marketing.press'))->name('press');
    Route::get('/ai', fn (): View => view('cloud::marketing.ai'))->name('ai');
    Route::get('/ai-native-crm', fn (): View => view('cloud::marketing.ai-native-crm'))->name('aiNativeCrm');
    Route::get('/self-hosted', fn (): View => view('cloud::marketing.self-hosted'))->name('selfHosted');

    foreach (ConnectableAssistant::cases() as $assistant) {
        Route::get("/crm-for-{$assistant->value}", [AssistantPagesController::class, 'show'])
            ->defaults('assistant', $assistant->value)
            ->name($assistant->routeName());
    }

    Route::get('/security', fn (): View => view('cloud::marketing.security'))->name('security');
    Route::get('/compare/relaticle-vs-{competitor}', [ComparisonController::class, 'show'])->name('compare.show');
    Route::get('/alternatives/{competitor}', [AlternativesController::class, 'show'])->name('alternatives.show');
    Route::get('/contact', [ContactController::class, 'show'])->name('contact');
    Route::post('/contact', [ContactController::class, 'store'])->middleware(['throttle:5,1,contact-form', ProtectAgainstSpam::class]);
});

Route::get('/discord', fn (): RedirectResponse => redirect()->away(config('services.discord.invite_url')))->name('discord');
