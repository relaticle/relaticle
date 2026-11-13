<?php

declare(strict_types=1);

use App\Features\Billing;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Relaticle\Cloud\Admin\Filament\Pages\Overview;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(Overview::class, SubscriptionResource::class, AiCreditBalanceResource::class, AiCreditTransactionResource::class);

beforeEach(function (): void {
    Feature::define(Billing::class, true);
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $this->actingAs(SystemAdministrator::factory()->administrator()->create(), 'sysadmin');
});

it('reads every cloud panel page an administrator is allowed to see', function (string $route): void {
    $this->get($route)->assertOk();
})->with([
    'overview' => '/sysadmin',
    'subscriptions' => '/sysadmin/billing/subscriptions',
    'ai credit balances' => '/sysadmin/ai/credit-balances',
    'ai credit transactions' => '/sysadmin/ai/credit-transactions',
]);
