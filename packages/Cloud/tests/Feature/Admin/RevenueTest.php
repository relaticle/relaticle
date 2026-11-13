<?php

declare(strict_types=1);

use App\Models\Workspace;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Cashier\Subscription;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource\Pages\ListSubscriptions;
use Relaticle\Cloud\Admin\Metrics\Revenue;
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
 * @param  array<string, array<string, mixed>|null>  $bySubscription
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
         * @param  array<string, array<string, mixed>|null>  $bySubscription
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
                return [json_encode(['error' => ['message' => 'Too many requests', 'type' => 'invalid_request_error', 'code' => 'rate_limit']]), 429, []];
            }

            if (str_starts_with($id, 'co_')) {
                $coupon = $this->bySubscription['coupons'][$id] ?? null;

                return $coupon === null
                    ? [json_encode(['error' => ['message' => "No such coupon: '{$id}'", 'type' => 'invalid_request_error', 'code' => 'resource_missing']]), 404, []]
                    : [json_encode(['id' => $id, 'object' => 'coupon', 'percent_off' => null, 'amount_off' => null, 'duration' => 'forever', ...$coupon]), 200, []];
            }

            $data = $this->bySubscription[$id];

            if ($data === null) {
                return [json_encode(['error' => [
                    'message' => "No such subscription: '{$id}'", 'type' => 'invalid_request_error', 'code' => 'resource_missing', 'param' => 'id',
                ]]), 404, []];
            }

            return [json_encode(stripeSubscription($id, $data)), 200, []];
        }
    });

    return $requests;
}

/**
 * @param  array<string, mixed>  $data
 * @return array<string, mixed>
 */
function stripeSubscription(string $id, array $data): array
{
    $coupon = fn (array $coupon): array => ['id' => 'co_'.Str::random(6), 'object' => 'coupon', 'percent_off' => null, 'amount_off' => null, 'duration' => 'forever', ...$coupon];
    $discount = fn (array $entry): array => [
        'id' => 'di_'.Str::random(6), 'object' => 'discount', 'end' => $entry['end'] ?? null,
        'source' => ['type' => 'coupon', 'coupon' => $coupon($entry['coupon'])],
    ];
    $invoice = $data['invoice'] ?? ['status' => 'paid', 'total' => 2_400, 'created' => now()->subDays(20)->getTimestamp()];

    return [
        'id' => $id,
        'object' => 'subscription',
        'items' => ['object' => 'list', 'data' => array_map(fn (array $item): array => [
            'id' => 'si_'.Str::random(6),
            'object' => 'subscription_item',
            'quantity' => $item['quantity'] ?? 1,
            'discounts' => array_map(fn (string $couponId): array => [
                'id' => 'di_'.Str::random(6), 'object' => 'discount', 'end' => null, 'source' => ['type' => 'coupon', 'coupon' => $couponId],
            ], $item['coupons'] ?? []),
            'price' => [
                'id' => 'price_x', 'object' => 'price', 'type' => 'recurring', 'unit_amount' => $item['cents'],
                'tax_behavior' => $item['tax_behavior'] ?? 'unspecified',
                'recurring' => ['interval' => $item['interval'] ?? 'month', 'interval_count' => 1, 'usage_type' => $item['usage_type'] ?? 'licensed'],
            ],
        ], $data['items'])],
        'discounts' => array_map($discount, $data['discounts'] ?? []),
        'latest_invoice' => match (true) {
            $invoice === null => null,
            $invoice === 'unexpanded' => 'in_'.$id,
            default => ['id' => 'in_'.$id, 'object' => 'invoice', ...$invoice],
        },
    ];
}

function payingWorkspace(Workspace $workspace, string $stripeId, string $status = 'active'): Subscription
{
    $workspace->forceFill(['stripe_id' => 'cus_'.$stripeId])->save();

    return $workspace->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => $stripeId,
        'stripe_status' => $status,
        'stripe_price' => 'price_x',
        'quantity' => 1,
    ]);
}

it('sums the plan price paid-up customers pay per month, before tax, without internal workspaces', function (): void {
    config()->set('system-admin.internal_email_domains', ['relaticle.com']);

    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_yearly');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_monthly');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::internalOwner()), 'sub_internal');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner(attributes: ['email' => 'reviewer@relaticle.com'])), 'sub_demo');

    fakeStripeSubscriptions([
        'sub_yearly' => ['items' => [['cents' => 22_800, 'interval' => 'year']]],
        'sub_monthly' => ['items' => [['cents' => 2_400]]],
        'sub_internal' => ['items' => [['cents' => 2_400]]],
        'sub_demo' => ['items' => [['cents' => 2_400]]],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(43_000_000);
});

it('leaves out trials and subscriptions whose payment failed', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_paid');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_failed', 'past_due');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_trial', 'trialing');

    fakeStripeSubscriptions([
        'sub_paid' => ['items' => [['cents' => 2_400]]],
        'sub_failed' => ['items' => [['cents' => 2_400]], 'invoice' => ['status' => 'open', 'created' => now()->subDay()->getTimestamp()]],
        'sub_trial' => ['items' => [['cents' => 2_400]]],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(24_000_000);
});

it('counts the plan price, not one-off charges on the latest invoice', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_with_pack');

    fakeStripeSubscriptions([
        'sub_with_pack' => ['items' => [['cents' => 2_400]], 'invoice' => ['status' => 'paid', 'created' => now()->subDay()->getTimestamp(), 'total_excluding_tax' => 18_300]],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(24_000_000);
});

it('nets recurring discounts and ignores one-time and expired ones', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_half');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_amount');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_once');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_expired');

    fakeStripeSubscriptions([
        'sub_half' => ['items' => [['cents' => 2_400]], 'discounts' => [['coupon' => ['percent_off' => 50]]]],
        'sub_amount' => ['items' => [['cents' => 22_800, 'interval' => 'year']], 'discounts' => [['coupon' => ['amount_off' => 1_200, 'duration' => 'repeating'], 'end' => now()->addMonths(6)->getTimestamp()]]],
        'sub_once' => ['items' => [['cents' => 2_400]], 'discounts' => [['coupon' => ['percent_off' => 100, 'duration' => 'once']]]],
        'sub_expired' => ['items' => [['cents' => 2_400]], 'discounts' => [['coupon' => ['percent_off' => 100, 'duration' => 'repeating'], 'end' => now()->subDay()->getTimestamp()]]],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(12_000_000 + 18_000_000 + 24_000_000 + 24_000_000);
});

it('multiplies the price by the quantity on the subscription item', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_seats');

    fakeStripeSubscriptions(['sub_seats' => ['items' => [['cents' => 2_400, 'quantity' => 3]]]]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(72_000_000);
});

it('reports a price it cannot read as unavailable, not as a guess', function (string $shape): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_odd');

    fakeStripeSubscriptions(['sub_odd' => ['items' => [match ($shape) {
        'metered' => ['cents' => 2_400, 'usage_type' => 'metered'],
        'tiered' => ['cents' => null],
        'tax inclusive' => ['cents' => 2_400, 'tax_behavior' => 'inclusive'],
    }]]]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
})->with(['metered', 'tiered', 'tax inclusive']);

it('compares with the subscriptions that were paying a week ago', function (): void {
    $weekAgo = now()->subWeek();
    $paying = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_steady');
    $paying->forceFill(['created_at' => now()->subMonths(2)])->save();
    $new = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_new');
    $new->forceFill(['created_at' => now()->subDays(3)])->save();
    $churned = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_churned', 'canceled');
    $churned->forceFill(['created_at' => now()->subMonths(2), 'ends_at' => now()->subDays(2)])->save();
    $renewalFailed = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_renewal_failed', 'past_due');
    $renewalFailed->forceFill(['created_at' => now()->subMonths(2), 'trial_ends_at' => now()->subMonths(2)->addDays(14)])->save();
    $neverPaid = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_never_paid', 'past_due');
    $neverPaid->forceFill(['created_at' => now()->subDays(20), 'trial_ends_at' => now()->subDays(2)])->save();

    fakeStripeSubscriptions([
        'sub_steady' => ['items' => [['cents' => 2_400]]],
        'sub_new' => ['items' => [['cents' => 5_900]]],
        'sub_churned' => ['items' => [['cents' => 1_000]]],
        'sub_renewal_failed' => ['items' => [['cents' => 100]], 'invoice' => ['status' => 'open', 'total' => 100, 'created' => now()->subDays(2)->getTimestamp()]],
        'sub_never_paid' => ['items' => [['cents' => 10_000]], 'invoice' => ['status' => 'open', 'total' => 10_000, 'created' => now()->subDays(2)->getTimestamp()]],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(83_000_000)
        ->and(resolve(Revenue::class)->monthlyMicros($weekAgo))->toBe(35_000_000);
});

it('leaves out an Enterprise plan whose first invoice is not paid yet', function (): void {
    $workspace = OverviewData::workspaceOf(OverviewData::owner());
    $workspace->forceFill(['stripe_id' => 'cus_managed'])->save();
    $workspace->subscriptions()->create([
        'type' => 'managed_pending', 'stripe_id' => 'sub_managed', 'stripe_status' => 'active', 'stripe_price' => 'price_x', 'quantity' => 1,
    ]);
    fakeStripeSubscriptions(['sub_managed' => ['items' => [['cents' => 600_000, 'interval' => 'year']], 'invoice' => ['status' => 'open', 'total' => 600_000, 'created' => now()->subDay()->getTimestamp()]]]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(0)
        ->and(resolve(Revenue::class)->monthlyMicros(now()->subWeek()))->toBe(0);
});

it('does not count a trial cancelled this week as paying last week', function (): void {
    $cancelled = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_cancelled_trial', 'canceled');
    $cancelled->forceFill(['created_at' => now()->subDays(16), 'trial_ends_at' => null, 'ends_at' => now()->subDays(2)])->save();
    fakeStripeSubscriptions(['sub_cancelled_trial' => ['items' => [['cents' => 2_400]], 'invoice' => ['status' => 'paid', 'total' => 0, 'created' => now()->subDays(16)->getTimestamp()]]]);

    expect(resolve(Revenue::class)->monthlyMicros(now()->subWeek()))->toBe(0);
});

it('keeps counting a renewal that is still inside its payment terms', function (): void {
    $renewing = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_terms');
    $renewing->forceFill(['created_at' => now()->subYear()])->save();
    fakeStripeSubscriptions(['sub_terms' => ['items' => [['cents' => 22_800, 'interval' => 'year']], 'invoice' => ['status' => 'open', 'total' => 22_800, 'created' => now()->subDays(10)->getTimestamp()]]]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(19_000_000)
        ->and(resolve(Revenue::class)->monthlyMicros(now()->subWeek()))->toBe(19_000_000);
});

it('nets a discount on a single subscription item', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_item_discount');
    fakeStripeSubscriptions([
        'sub_item_discount' => ['items' => [['cents' => 2_400, 'coupons' => ['co_quarter']]]],
        'coupons' => ['co_quarter' => ['percent_off' => 25]],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(18_000_000);
});

it('reports a subscription whose coupon cannot be read as unavailable, not as zero', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_deleted_coupon');
    fakeStripeSubscriptions(['sub_deleted_coupon' => ['items' => [['cents' => 2_400, 'coupons' => ['co_deleted']]]], 'coupons' => []]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
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

it('reads Stripe again as soon as the subscription changes', function (): void {
    $subscription = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_changed');
    $requests = fakeStripeSubscriptions(['sub_changed' => ['items' => [['cents' => 2_400]]]]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(24_000_000);

    $this->travelTo(now()->addMinute());
    $subscription->forceFill(['quantity' => 2])->save();

    resolve(Revenue::class)->monthlyMicros();

    expect($requests)->toHaveCount(2);
});

it('reports an invoice Stripe returned only as an id as unavailable, not as zero', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_unexpanded');
    fakeStripeSubscriptions(['sub_unexpanded' => ['items' => [['cents' => 1_200]], 'invoice' => 'unexpanded']]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
});

it('counts a subscription Stripe no longer knows as zero and keeps summing the rest', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_stale');
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_current');
    $requests = fakeStripeSubscriptions([
        'sub_stale' => null,
        'sub_current' => ['items' => [['cents' => 1_200]]],
    ]);

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(12_000_000);

    $this->travelTo(now()->addMinutes(50));

    expect(resolve(Revenue::class)->monthlyMicros())->toBe(12_000_000)
        ->and($requests)->toHaveCount(2);
});

it('reports a rate-limited subscription as unavailable rather than zero', function (): void {
    payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_busy');
    fakeStripeSubscriptions(['sub_busy' => ['items' => [['cents' => 1_200]]]], rateLimited: ['sub_busy']);

    expect(resolve(Revenue::class)->monthlyMicros())->toBeNull();
});

it('lists the subscriptions that count toward MRR', function (): void {
    $counted = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_counted');
    $internal = payingWorkspace(OverviewData::workspaceOf(OverviewData::internalOwner()), 'sub_internal_list');
    $ended = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_ended');
    $ended->forceFill(['stripe_status' => 'canceled', 'ends_at' => now()->subDay()])->save();
    $failed = payingWorkspace(OverviewData::workspaceOf(OverviewData::owner()), 'sub_failed_list', 'past_due');

    livewire(ListSubscriptions::class)
        ->filterTable('counts_toward_mrr')
        ->assertCanSeeTableRecords([$counted])
        ->assertCanNotSeeTableRecords([$internal, $ended, $failed]);
});
