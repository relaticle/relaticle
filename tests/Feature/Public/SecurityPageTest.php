<?php

declare(strict_types=1);

use App\Features\Billing as BillingFeature;
use App\Features\EmailIntegration;
use App\Features\SignupChallenge;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\ModelRegistry;
use Tests\Helpers\ChatCatalog;

function securityPageText(string $html): string
{
    $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html) ?? $html;
    $text = strip_tags($text);

    return trim(preg_replace('/\s+/', ' ', html_entity_decode($text)) ?? $text);
}

it('renders the security page with its structured data', function (): void {
    $html = $this->get('/security')->assertOk()->getContent();

    expect($html)->toContain(__('How Relaticle protects your data'))
        ->and($html)->toContain('"FAQPage"')
        ->and($html)->toContain('"BreadcrumbList"')
        ->and($html)->toContain(route('security'));
});

it('answers the first four questions at a glance and links each to a section on the page', function (): void {
    $html = $this->get('/security')->assertOk()->getContent();

    expect(securityPageText($html))->toContain('Relaticle does not train AI models on your CRM data.')
        ->and(securityPageText($html))->toContain(config('chat.assistant_name').' asks first');

    foreach (['ai', 'account', 'data'] as $anchor) {
        expect($html)->toContain('href="#'.$anchor.'"')
            ->and($html)->toContain('id="'.$anchor.'"');
    }
});

it('gives the provider table the anchor the privacy policy links to', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/security')->assertOk()->assertSee('id="providers"', false);
});

it('names each service provider on a hosted install', function (string $provider): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/security')->assertOk()->assertSee($provider);
})->with(['Hetzner', 'Laravel Forge', 'Mailcoach', 'Postmark', 'Stripe', 'Sentry', 'Fathom Analytics', 'Anthropic, OpenAI', 'Google, DuckDuckGo', 'Maxforms', 'Oh Dear']);

it('says which country the hosted data lives in', function (): void {
    Feature::define(BillingFeature::class, true);

    $this->get('/security')->assertOk()->assertSee('Hosts the application and its database, in Germany');
});

it('names the operator instead of a provider table where hosted billing is off', function (): void {
    Feature::define(BillingFeature::class, false);

    $this->get('/security')->assertOk()
        ->assertSee('id="providers"', false)
        ->assertSee('This install is run by its own operator.')
        ->assertDontSee('Hetzner')
        ->assertDontSee('Laravel Forge');
});

it('names the AI providers of the models the product offers', function (): void {
    Feature::define(BillingFeature::class, true);
    config()->set('chat.models', [
        ChatCatalog::entry(),
        ChatCatalog::entry(['label' => 'Gemini 3 Flash', 'provider' => 'gemini', 'model' => 'gemini-3-flash']),
    ]);
    app()->forgetInstance(ModelRegistry::class);

    $text = securityPageText($this->get('/security')->assertOk()->getContent());

    expect($text)->toContain('to an AI provider: Anthropic or Google.')
        ->and($text)->toContain('Anthropic, Google Run the AI models')
        ->and($text)->not->toContain('OpenAI Run the AI models');
});

it('lists Cloudflare only while the signup challenge is on', function (bool $enabled): void {
    Feature::define(BillingFeature::class, true);
    Feature::define(SignupChallenge::class, $enabled);
    config()->set('services.turnstile.key', 'site-key');
    config()->set('services.turnstile.secret', 'secret-key');

    $response = $this->get('/security')->assertOk();

    $enabled
        ? $response->assertSee('Checks that a new account is created by a person')
        : $response->assertDontSee('Checks that a new account is created by a person');
})->with([true, false]);

it('says an error report carries no account identity unless the install sends it', function (bool $sendsIdentity, string $expected): void {
    Feature::define(BillingFeature::class, true);
    config()->set('sentry.send_default_pii', $sendsIdentity);

    $this->get('/security')->assertOk()->assertSee($expected);
})->with([
    [false, 'Error reports without your account identity.'],
    [true, 'Error reports, which can include your account identity'],
]);

it('says account deletion takes the product update subscription with it', function (): void {
    $this->get('/security')->assertOk()
        ->assertSee('removed after a 30-day grace period, together with its product update subscription');
});

it('says plainly that it holds no SOC 2 or ISO 27001 certification', function (): void {
    $text = securityPageText($this->get('/security')->assertOk()->getContent());

    expect($text)->toContain('Is Relaticle SOC 2 or ISO 27001 certified? No. Relaticle holds neither certification today.');
});

it('scopes the approval step to the built-in assistant and says connected assistants write directly', function (): void {
    $text = securityPageText($this->get('/security')->assertOk()->getContent());

    expect($text)->toContain(config('chat.assistant_name').' proposes every change as a card and waits for your approval.')
        ->and($text)->toContain('Their changes apply directly.');
});

it('links the privacy policy, the security contact and security.txt', function (): void {
    $html = $this->get('/security')->assertOk()->getContent();

    expect($html)->toContain('href="'.route('policy.show').'"')
        ->and($html)->toContain('href="mailto:security@relaticle.com"')
        ->and($html)->toContain('href="'.route('securityTxt').'"');
});

it('describes mailbox handling where the email integration is on', function (): void {
    config()->set('relaticle.features.email_integration', true);
    Feature::for(null)->activate(EmailIntegration::class);

    $this->get('/security')->assertOk()
        ->assertSee('never changes, labels or deletes messages in your mailbox')
        ->assertSee('It sends email and answers invitations only when you do that from Relaticle.');
});

it('leaves mailbox handling out where the email integration is off', function (): void {
    config()->set('relaticle.features.email_integration', false);
    Feature::for(null)->deactivate(EmailIntegration::class);

    $this->get('/security')->assertOk()->assertDontSee('never changes, labels or deletes messages in your mailbox');
});

it('is linked from the privacy policy and the footer', function (): void {
    $this->get('/privacy-policy')->assertOk()->assertSee('/security#providers', false);

    $home = $this->get('/')->assertOk()->getContent();

    preg_match('/<footer[\s\S]*?<\/footer>/', $home, $footer);

    expect($footer[0] ?? '')->toContain('href="'.route('security').'"');
});

it('names the built-in assistant from config rather than a hardcoded literal', function (): void {
    config()->set('chat.assistant_name', 'Testbot');

    $this->get('/security')->assertOk()->assertSee('Testbot');
});
