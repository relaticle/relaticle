<?php

declare(strict_types=1);

use App\Actions\Billing\CreateCreditPackCheckout;
use App\Actions\Billing\CreateProCheckout;
use App\Models\Team;
use App\Models\User;

mutates(CreateProCheckout::class);

beforeEach(function (): void {
    config()->set('services.stripe.prices.pro_monthly', 'price_pro_monthly_test');
    config()->set('services.stripe.prices.pro_yearly', 'price_pro_yearly_test');
});

function checkoutTeam(): Team
{
    /** @var Team $team */
    $team = User::factory()->withPersonalTeam()->create()->currentTeam;

    return $team;
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
        ->and(invokeCheckout('sessionOptions', [checkoutTeam()]))->toHaveKey('managed_payments.enabled', true);
});

it('rejects an interval that is not a configured billing period', function (): void {
    expect(fn (): mixed => invokeCheckout('priceId', ['weekly']))
        ->toThrow(InvalidArgumentException::class);
});

it('omits managed payments when the switch is off', function (): void {
    config()->set('services.stripe.managed_payments', false);

    $options = invokeCheckout('sessionOptions', [checkoutTeam()]);

    expect($options)->not->toHaveKey('managed_payments');
});

it('selects the yearly price for the yearly interval', function (): void {
    expect(invokeCheckout('priceId', ['yearly']))->toBe('price_pro_yearly_test');
});

it('points success and cancel urls at the team billing page', function (): void {
    $team = checkoutTeam();

    $options = invokeCheckout('sessionOptions', [$team]);

    expect($options['success_url'])->toContain("/app/{$team->slug}/billing")
        ->and($options['success_url'])->toContain('checkout=success')
        ->and($options['cancel_url'])->toContain("/app/{$team->slug}/billing");
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

    $team = checkoutTeam();
    $options = invokePackCheckout('sessionOptions', [$team, 'price_credits_1k_test']);

    expect($options)->toHaveKey('managed_payments.enabled', true)
        ->and($options['metadata']['team_id'])->toBe((string) $team->getKey())
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
