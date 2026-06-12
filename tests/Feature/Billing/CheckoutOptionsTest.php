<?php

declare(strict_types=1);

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

it('builds monthly checkout options with managed payments enabled', function (): void {
    config()->set('services.stripe.managed_payments', true);

    $action = app(CreateProCheckout::class);

    expect($action->priceId('monthly'))->toBe('price_pro_monthly_test')
        ->and($action->sessionOptions(checkoutTeam()))->toHaveKey('managed_payments.enabled', true)
        ->and($action->sessionOptions(checkoutTeam()))->toHaveKey('allow_promotion_codes', true);
});

it('omits managed payments when the switch is off', function (): void {
    config()->set('services.stripe.managed_payments', false);

    $options = app(CreateProCheckout::class)->sessionOptions(checkoutTeam());

    expect($options)->not->toHaveKey('managed_payments');
});

it('selects the yearly price for the yearly interval', function (): void {
    expect(app(CreateProCheckout::class)->priceId('yearly'))->toBe('price_pro_yearly_test');
});

it('points success and cancel urls at the team billing page', function (): void {
    $team = checkoutTeam();

    $options = app(CreateProCheckout::class)->sessionOptions($team);

    expect($options['success_url'])->toContain("/app/{$team->slug}/billing")
        ->and($options['success_url'])->toContain('checkout=success')
        ->and($options['cancel_url'])->toContain("/app/{$team->slug}/billing");
});
