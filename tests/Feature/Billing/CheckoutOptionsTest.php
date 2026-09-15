<?php

declare(strict_types=1);

use App\Actions\Billing\CreateCreditPackCheckout;
use App\Actions\Billing\CreateProCheckout;
use App\Mail\ProTrialEndingSoonMail;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Foundation\Testing\CachedState;

mutates(CreateProCheckout::class);

beforeEach(function (): void {
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
});

function checkoutWorkspace(): Workspace
{
    /** @var Workspace $workspace */
    $workspace = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;

    return $workspace;
}

/**
 * The checkout action exposes only execute() publicly (single-execute convention);
 * its price/option helpers are private, so we reach them via reflection to assert
 * the option-building logic without making a real Stripe call.
 *
 * @param  array<int, mixed>  $args
 */
function invokeCheckout(string $method, array $args): mixed
{
    return (new ReflectionMethod(CreateProCheckout::class, $method))
        ->invoke(app(CreateProCheckout::class), ...$args);
}

it('builds monthly checkout options with managed payments enabled', function (): void {
    config()->set('services.stripe.managed_payments', true);

    expect(invokeCheckout('priceId', ['monthly']))->toBe('price_pro_monthly_test')
        ->and(invokeCheckout('sessionOptions', [checkoutWorkspace(), 'light']))->toHaveKey('managed_payments.enabled', true);
});

it('rejects an interval that is not a configured billing period', function (): void {
    expect(fn (): mixed => invokeCheckout('priceId', ['weekly']))
        ->toThrow(InvalidArgumentException::class);
});

it('omits managed payments when the switch is off', function (): void {
    config()->set('services.stripe.managed_payments', false);

    $options = invokeCheckout('sessionOptions', [checkoutWorkspace(), 'light']);

    expect($options)->not->toHaveKey('managed_payments');
});

it('selects the yearly price for the yearly interval', function (): void {
    expect(invokeCheckout('priceId', ['yearly']))->toBe('price_pro_yearly_test');
});

it('builds an embedded session that returns to the workspace billing page', function (): void {
    $workspace = checkoutWorkspace();

    $options = invokeCheckout('sessionOptions', [$workspace, 'light']);

    expect($options['ui_mode'])->toBe('embedded')
        ->and($options['redirect_on_completion'])->toBe('if_required')
        ->and($options['return_url'])->toContain("/app/{$workspace->slug}/billing")
        ->and($options['return_url'])->toContain('checkout=success');
});

/** @param array<int, mixed> $args */
function invokePackCheckout(string $method, array $args): mixed
{
    return (new ReflectionMethod(CreateCreditPackCheckout::class, $method))
        ->invoke(app(CreateCreditPackCheckout::class), ...$args);
}

it('builds pack checkout options with metadata and managed payments', function (): void {
    config()->set('services.stripe.managed_payments', true);
    config()->set('services.stripe.credit_packs.small.price', 'price_credits_1k_test');

    $workspace = checkoutWorkspace();
    $options = invokePackCheckout('sessionOptions', [$workspace, 'price_credits_1k_test']);

    expect($options)->toHaveKey('managed_payments.enabled', true)
        ->and($options['metadata']['team_id'])->toBe((string) $workspace->getKey())
        ->and($options['metadata']['credit_pack_price'])->toBe('price_credits_1k_test')
        ->and($options['success_url'])->toContain('credits=success');
});

it('rejects an unknown pack key', function (): void {
    expect(fn (): mixed => invokePackCheckout('priceId', ['mega']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a pack whose price is not configured', function (): void {
    config()->set('services.stripe.credit_packs.small.price', null);

    expect(fn (): mixed => invokePackCheckout('priceId', ['small']))
        ->toThrow(InvalidArgumentException::class);
});

/**
 * The domain-mode block below rebuilds the application so the panel registers its
 * routes against a domain. That reboot drops Laravel's parallel-testing database
 * override, pointing the fresh app at the base test database instead of this
 * worker's, so these cases must not touch the database at all. An in-memory Workspace
 * is enough: the URL builders only read the tenant slug.
 */
function unsavedWorkspace(): Workspace
{
    return new Workspace(['slug' => 'acme', 'name' => 'Acme']);
}

/**
 * A subdomain-routed app panel (APP_PANEL_DOMAIN, as production runs) serves the
 * billing page at {domain}/{slug}/billing. There is no "/app" path prefix. Any
 * URL handed to Stripe has to follow the registered route, otherwise returning
 * from Checkout lands on a 404.
 */
describe('domain-routed app panel', function (): void {
    beforeEach(function (): void {
        putenv('APP_PANEL_DOMAIN=app.example.com');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
        RouteServiceProvider::loadCachedRoutesUsing(null);
        LoadConfiguration::alwaysUse(null);
        $this->refreshApplication();

        config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
        config()->set('services.stripe.credit_packs.small.price', 'price_credits_1k_test');
    });

    afterEach(function (): void {
        putenv('APP_PANEL_DOMAIN');
        CachedState::$cachedRoutes = null;
        CachedState::$cachedConfig = null;
    });

    it('returns from pro checkout to the billing route without a panel path prefix', function (): void {
        $options = invokeCheckout('sessionOptions', [unsavedWorkspace(), 'light']);

        expect($options['return_url'])->toBe('http://app.example.com/acme/billing?checkout=success');
    });

    it('returns from credit pack checkout to the billing route without a panel path prefix', function (): void {
        $options = invokePackCheckout('sessionOptions', [unsavedWorkspace(), 'price_credits_1k_test']);

        expect($options['cancel_url'])->toBe('http://app.example.com/acme/billing')
            ->and($options['success_url'])->toBe('http://app.example.com/acme/billing?credits=success');
    });

    it('links the trial reminder email at the billing route without a panel path prefix', function (): void {
        expect((string) (new ProTrialEndingSoonMail(unsavedWorkspace()))->render())
            ->toContain('http://app.example.com/acme/billing')
            ->not->toContain('/app/acme/billing');
    });
});
