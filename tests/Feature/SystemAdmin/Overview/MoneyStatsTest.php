<?php

declare(strict_types=1);

use App\Features\Billing;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Laravel\Pennant\Feature;
use Relaticle\SystemAdmin\Filament\Pages\Overview;
use Relaticle\SystemAdmin\Filament\Widgets\Overview\MoneyStats;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Helpers\OverviewData;

mutates(MoneyStats::class, Overview::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Cache::flush();
    Feature::define(Billing::class, true);
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});

it('renders on an empty database with MRR and no errors', function (): void {
    livewire(MoneyStats::class)
        ->assertOk()
        ->assertSee('Are we making money?')
        ->assertSee('MRR')
        ->assertSee('+$0.00 vs last week')
        ->assertDontSee('AI cost this month')
        ->assertSeeHtml('fi-color-primary');
});

it('is the panel home and opens on the money row', function (): void {
    $this->get(Overview::getUrl())->assertOk();

    expect(livewire(Overview::class)->instance()->getVisibleWidgets()[0])->toBe(MoneyStats::class);
});

it('leaves the money row out when billing is off', function (): void {
    Feature::define(Billing::class, false);

    expect(livewire(Overview::class)->instance()->getVisibleWidgets())->not->toContain(MoneyStats::class);
});

it('keeps MRR typed when the cache hands it back as a string', function (): void {
    Cache::extend('stringifying', fn (): Repository => new Repository(new class extends ArrayStore
    {
        public function put(mixed $key, mixed $value, mixed $seconds): bool
        {
            return parent::put($key, is_int($value) || is_float($value) ? (string) $value : $value, $seconds);
        }
    }));
    config()->set('cache.stores.stringifying', ['driver' => 'stringifying']);
    config()->set('cache.default', 'stringifying');

    livewire(MoneyStats::class)->assertSee('+$0.00 vs last week');

    livewire(MoneyStats::class)
        ->assertSee('+$0.00 vs last week')
        ->assertDontSee('Stripe unavailable');
});

it('shows MRR gray when Stripe is unavailable', function (): void {
    config()->set('cashier.secret', 'sk_test_fake');
    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    $workspace->forceFill(['stripe_id' => 'cus_unreachable'])->save();
    $workspace->subscriptions()->create([
        'type' => 'default', 'stripe_id' => 'sub_unreachable', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
    ]);
    ApiRequestor::setHttpClient(new class implements ClientInterface
    {
        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            throw new RuntimeException('Stripe is unreachable');
        }
    });

    $html = livewire(MoneyStats::class)->assertSee('Stripe unavailable')->html();

    expect($html)->not->toContain('fi-color-primary');
});

it('explains how MRR is counted in a tooltip', function (): void {
    livewire(MoneyStats::class)
        ->assertSeeHtml('x-tooltip')
        ->assertSee('Each subscription counts its latest invoice, split into months');
});
