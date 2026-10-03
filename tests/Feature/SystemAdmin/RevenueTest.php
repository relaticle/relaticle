<?php

declare(strict_types=1);

use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\SystemAdmin\Metrics\Revenue;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;
use Tests\Helpers\OverviewData;

mutates(Revenue::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    config()->set('cashier.secret', 'sk_test_fake');
    Cache::flush();
});

afterEach(function (): void {
    ApiRequestor::setHttpClient(null);
});

/**
 * @param  array<string, array{cents: int|null, interval: string, expanded?: bool}|null>  $bySubscription
 * @param  list<string>  $rateLimited
 * @return ArrayObject<int, string>
 */
function fakeStripeSubscriptions(array $bySubscription, bool $fails = false, array $rateLimited = []): ArrayObject
{
    /** @var ArrayObject<int, string> $requests */
    $requests = new ArrayObject;

    ApiRequestor::setHttpClient(new readonly class($bySubscription, $fails, $requests, $rateLimited) implements ClientInterface
    {
        /**
         * @param  array<string, array{cents: int|null, interval: string, expanded?: bool}|null>  $bySubscription
         * @param  ArrayObject<int, string>  $requests
         * @param  list<string>  $rateLimited
         */
        public function __construct(private array $bySubscription, private bool $fails, private ArrayObject $requests, private array $rateLimited) {}

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $id = basename(parse_url((string) $absUrl, PHP_URL_PATH) ?: '');
            $this->requests->append($id);

            throw_if($this->fails, RuntimeException::class, 'Stripe is unreachable');

            if (in_array($id, $this->rateLimited, true)) {
                return [json_encode(['error' => [
                    'message' => 'Too many requests',
                    'type' => 'invalid_request_error',
                    'code' => 'rate_limit',
                ]]), 429, []];
            }

            $data = $this->bySubscription[$id];

            if ($data === null) {
                return [json_encode(['error' => [
                    'message' => "No such subscription: '{$id}'",
                    'type' => 'invalid_request_error',
                    'code' => 'resource_missing',
                    'param' => 'id',
                ]]), 404, []];
            }

            $expandsInvoice = in_array('latest_invoice', (array) ($params['expand'] ?? []), true) && ($data['expanded'] ?? true);
            $invoice = $expandsInvoice
                ? ['id' => 'in_'.$id, 'object' => 'invoice', 'total_excluding_tax' => $data['cents']]
                : 'in_'.$id;

            return [json_encode([
                'id' => $id,
                'object' => 'subscription',
                'items' => ['object' => 'list', 'data' => [[
                    'id' => 'si_'.$id,
                    'object' => 'subscription_item',
                    'price' => ['id' => 'price_x', 'object' => 'price', 'recurring' => ['interval' => $data['interval'], 'interval_count' => 1]],
                ]]],
                'latest_invoice' => $data['cents'] === null ? null : $invoice,
            ]), 200, []];
        }
    });

    return $requests;
}

function payingWorkspace(Workspace $workspace, string $stripeId): Subscription
{
    $workspace->forceFill(['stripe_id' => 'cus_'.$stripeId])->save();

    return $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => 'active',
        'stripe_price' => 'price_x',
        'quantity' => 1,
    ]);
}

it('sums what customers actually paid per month, before tax, without internal workspaces', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_yearly');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_promo');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_unbilled');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::internalOwner()), 'sub_internal');

    fakeStripeSubscriptions([
        'sub_yearly' => ['cents' => 22_800, 'interval' => 'year'],
        'sub_promo' => ['cents' => 1_200, 'interval' => 'month'],
        'sub_unbilled' => ['cents' => null, 'interval' => 'month'],
        'sub_internal' => ['cents' => 2_400, 'interval' => 'month'],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(31_000_000);
});

it('reports Stripe being unavailable instead of a wrong number', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_down');
    fakeStripeSubscriptions([], fails: true);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
});

it('does not retry a failing Stripe for ten minutes', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_down');
    $requests = fakeStripeSubscriptions([], fails: true);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();

    $this->travelTo(now()->addMinutes(5));

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull()
        ->and($requests)->toHaveCount(1);

    $this->travelTo(now()->addMinutes(6));

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull()
        ->and($requests)->toHaveCount(2);
});

it('reports an invoice Stripe returned only as an id as unavailable, not as zero', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_unexpanded');
    fakeStripeSubscriptions(['sub_unexpanded' => ['cents' => 1_200, 'interval' => 'month', 'expanded' => false]]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
});

it('never subtracts a credit invoice from MRR', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_credit');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_paid');
    fakeStripeSubscriptions([
        'sub_credit' => ['cents' => -334, 'interval' => 'month'],
        'sub_paid' => ['cents' => 1_200, 'interval' => 'month'],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(12_000_000);
});

it('counts a subscription Stripe no longer knows as zero and keeps summing the rest', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_stale');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_current');
    $requests = fakeStripeSubscriptions([
        'sub_stale' => null,
        'sub_current' => ['cents' => 1_200, 'interval' => 'month'],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(12_000_000);

    $this->travelTo(now()->addHours(23));

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(12_000_000)
        ->and($requests)->toHaveCount(2);
});

it('reports a rate-limited subscription as unavailable rather than zero', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_busy');
    fakeStripeSubscriptions(['sub_busy' => ['cents' => 1_200, 'interval' => 'month']], rateLimited: ['sub_busy']);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
});

it('lists the subscriptions that count toward MRR', function (): void {
    $counted = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_counted');
    $internal = payingWorkspace(OverviewData::workspaceOf(OverviewData::internalOwner()), 'sub_internal_list');
    $ended = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_ended');
    $ended->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()])->save();

    livewire(ListSubscriptions::class)
        ->filterTable('counts_toward_mrr')
        ->assertCanSeeTableRecords([$counted])
        ->assertCanNotSeeTableRecords([$internal, $ended]);
});
