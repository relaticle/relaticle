<?php

declare(strict_types=1);

use App\Contracts\Entitlements;
use App\Enums\Plan;
use App\Enums\SubscriptionType;
use App\Models\Workspace;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Subscription;
use Livewire\Features\SupportTesting\Testable;
use Relaticle\SystemAdmin\Actions\StartManagedPlan;
use Relaticle\SystemAdmin\Actions\WithdrawManagedInvoice;
use Relaticle\SystemAdmin\Enums\SystemAdministratorRole;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ListWorkspaces;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource\Pages\ViewWorkspace;
use Relaticle\SystemAdmin\Filament\Support\ManagedPlan;
use Relaticle\SystemAdmin\Mail\ManagedPlanInvoiceMail;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Helpers\OverviewData;
use Tests\Helpers\StripeRecorder;

mutates(StartManagedPlan::class, WithdrawManagedInvoice::class, ManagedPlan::class, ManagedPlanInvoiceMail::class);

const HOSTED_INVOICE_URL = 'https://invoice.stripe.com/i/acct_test/test_managed';

const INVOICE_DUE_DATE = 1795996800;

beforeEach(function (): void {
    config()->set('cashier.secret', 'sk_test_fake');
    config()->set('services.stripe.products.concierge', 'prod_concierge_test');
    config()->set('services.stripe.products.enterprise', 'prod_enterprise_test');
    config()->set('services.stripe.managed_days_until_due', 30);

    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    $this->stripe = StripeRecorder::install()
        ->respondTo('/v1/customers', ['id' => 'cus_managed_test', 'object' => 'customer', 'email' => 'owner@example.test', 'balance' => 0])
        ->respondTo('/v1/subscriptions', managedSubscriptionBody())
        ->respondTo('/v1/invoices/in_managed_test/send', ['id' => 'in_managed_test', 'object' => 'invoice', 'hosted_invoice_url' => HOSTED_INVOICE_URL, 'due_date' => INVOICE_DUE_DATE]);
});

afterEach(function (): void {
    StripeRecorder::uninstall();
});

/** @return array<string, mixed> */
function managedSubscriptionBody(): array
{
    return [
        'id' => 'sub_managed_test',
        'object' => 'subscription',
        'customer' => 'cus_managed_test',
        'status' => 'active',
        'latest_invoice' => ['id' => 'in_managed_test', 'object' => 'invoice'],
        'items' => [
            'object' => 'list',
            'data' => [[
                'id' => 'si_managed_test',
                'object' => 'subscription_item',
                'price' => ['id' => 'price_managed_test', 'object' => 'price', 'product' => 'prod_concierge_test'],
                'quantity' => 1,
            ]],
        ],
    ];
}

function managedPlanWorkspace(): Workspace
{
    return OverviewData::workspaceOf(OverviewData::owner());
}

/** @param  array<string, mixed>  $data */
function startManagedPlanFor(Workspace $workspace, array $data): Testable
{
    return livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('startManagedPlan')->table($workspace), ['amount_confirmation' => $data['amount'] ?? null, ...$data]);
}

function subscriptionRequests(StripeRecorder $stripe): int
{
    return count(array_filter($stripe->requests, fn (array $request): bool => str_ends_with($request['url'], '/v1/subscriptions')));
}

it('starts a Concierge subscription billed by invoice at the configured yearly price', function (): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    $params = $this->stripe->paramsFor('/v1/subscriptions');
    $subscription = $workspace->subscription(SubscriptionType::ManagedPending->value);

    expect($params['collection_method'])->toBe('send_invoice')
        ->and($params['days_until_due'])->toBe(30)
        ->and($params['payment_behavior'])->toBe('allow_incomplete')
        ->and($params['items'][0]['price_data']['unit_amount'])->toBe(70800)
        ->and($params['items'][0]['price_data']['product'])->toBe('prod_concierge_test')
        ->and($params['items'][0]['price_data']['recurring']['interval'])->toBe('year')
        ->and($params['metadata']['workspace_id'])->toBe((string) $workspace->getKey())
        ->and($params['metadata']['type'])->toBe('managed_pending')
        ->and($this->stripe->paramsFor('/v1/invoices/in_managed_test/send'))->toBe([])
        ->and($subscription?->stripe_id)->toBe('sub_managed_test')
        ->and($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('creates the Stripe customer with the owner email and the workspace name', function (): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    $params = $this->stripe->paramsFor('/v1/customers');

    expect($params['email'])->toBe($workspace->owner->email)
        ->and($params['name'])->toBe($workspace->name);
});

it('sets the owner email on an existing Stripe customer that has none', function (): void {
    $workspace = managedPlanWorkspace();
    $workspace->forceFill(['stripe_id' => 'cus_existing_test'])->save();
    $this->stripe->respondTo('/v1/customers', ['id' => 'cus_existing_test', 'object' => 'customer', 'email' => null, 'balance' => 0]);

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    $updates = array_filter(
        $this->stripe->requests,
        fn (array $request): bool => str_ends_with($request['url'], '/v1/customers/cus_existing_test') && ($request['params']['email'] ?? null) === $workspace->owner->email,
    );

    expect($updates)->toHaveCount(1);
});

it('leaves an existing Stripe customer that already has an email alone', function (): void {
    $workspace = managedPlanWorkspace();
    $workspace->forceFill(['stripe_id' => 'cus_existing_test'])->save();
    $this->stripe->respondTo('/v1/customers', ['id' => 'cus_existing_test', 'object' => 'customer', 'email' => 'billing@example.test', 'balance' => 0]);

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    $updates = array_filter($this->stripe->requests, fn (array $request): bool => isset($request['params']['email']));

    expect($updates)->toBeEmpty();
});

it('invoices Enterprise at the amount the sysadmin types', function (): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Enterprise->value, 'amount' => 32000])
        ->assertHasNoActionErrors();

    $price = $this->stripe->paramsFor('/v1/subscriptions')['items'][0]['price_data'];

    expect($price['unit_amount'])->toBe(3200000)
        ->and($price['product'])->toBe('prod_enterprise_test');
});

it('offers each managed plan its default yearly amount', function (): void {
    $workspace = managedPlanWorkspace();

    livewire(ListWorkspaces::class)
        ->mountAction(TestAction::make('startManagedPlan')->table($workspace))
        ->fillForm(['plan' => Plan::Concierge->value])
        ->assertSchemaStateSet(['amount' => 708])
        ->fillForm(['plan' => Plan::Enterprise->value])
        ->assertSchemaStateSet(['amount' => 20000]);
});

it('keeps a paused workspace paused while the managed invoice is unpaid', function (): void {
    withHostedBilling();
    $workspace = managedPlanWorkspace();
    $workspace->forceFill(['pro_trial_used_at' => now()->subMonth(), 'trial_ends_at' => now()->subDay()])->save();

    expect(resolve(Entitlements::class)->allowsAccess($workspace))->toBeFalse();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    expect(resolve(Entitlements::class)->allowsAccess($workspace->refresh()))->toBeFalse();
});

it('keeps a running trial when the managed invoice is sent', function (): void {
    $workspace = OverviewData::trial(managedPlanWorkspace());
    $endsAt = $workspace->trial_ends_at;

    startManagedPlanFor($workspace, ['plan' => Plan::Enterprise->value, 'amount' => 20000])
        ->assertHasNoActionErrors();

    expect($workspace->refresh()->trial_ends_at?->equalTo($endsAt))->toBeTrue();
});

it('mails the billing contact the hosted invoice link', function (): void {
    Mail::fake();
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708, 'billing_email' => 'finance@example.test'])
        ->assertHasNoActionErrors();

    $dueOn = Date::createFromTimestamp(INVOICE_DUE_DATE)->toFormattedDateString();

    Mail::assertQueued(ManagedPlanInvoiceMail::class, fn (ManagedPlanInvoiceMail $mail): bool => $mail->hasTo('finance@example.test')
        && str_contains($mail->render(), HOSTED_INVOICE_URL)
        && str_contains($mail->render(), "is \$708, due {$dueOn}. The plan switches on when it is paid."));
});

it('mails the billing contact without a due date when Stripe sends none', function (): void {
    Mail::fake();
    $workspace = managedPlanWorkspace();
    $this->stripe->respondTo('/v1/invoices/in_managed_test/send', ['id' => 'in_managed_test', 'object' => 'invoice', 'hosted_invoice_url' => HOSTED_INVOICE_URL, 'due_date' => null]);

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708, 'billing_email' => 'finance@example.test'])
        ->assertHasNoActionErrors();

    Mail::assertQueued(ManagedPlanInvoiceMail::class, fn (ManagedPlanInvoiceMail $mail): bool => str_contains($mail->render(), 'is $708. The plan switches on when it is paid.'));
});

it('mails nobody when the billing email is empty', function (): void {
    Mail::fake();

    startManagedPlanFor(managedPlanWorkspace(), ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    Mail::assertNothingQueued();
});

it('refuses while another managed invoice is unpaid', function (): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    startManagedPlanFor($workspace, ['plan' => Plan::Enterprise->value, 'amount' => 20000])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(1);
});

it('tells the sysadmin to reload when Stripe fails to start the plan', function (string $failingRequest): void {
    $workspace = managedPlanWorkspace();
    $this->stripe->respondTo($failingRequest, ['error' => ['type' => 'api_error', 'message' => 'Stripe is having trouble.']], 500);

    Exceptions::fake();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertNotified(
            Notification::make()
                ->danger()
                ->title('Invoice not sent')
                ->body('Stripe did not complete the request. Reload the page before trying again: the subscription may already exist.'),
        );

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), (string) $workspace->getKey())
        && $exception->getPrevious() instanceof ApiErrorException);
})->with([
    'creating the subscription' => ['/v1/subscriptions'],
    'sending the invoice' => ['/v1/invoices/in_managed_test/send'],
]);

it('refuses a second start while the first is still talking to Stripe', function (): void {
    $workspace = managedPlanWorkspace();
    $lock = Cache::lock("managed-plan-start:{$workspace->getKey()}", 60);
    $lock->get();

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasActionErrors(['plan']);

    $lock->release();

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('sends nothing when the retyped yearly amount does not match', function (?int $retyped): void {
    $workspace = managedPlanWorkspace();

    startManagedPlanFor($workspace, ['plan' => Plan::Enterprise->value, 'amount' => 200000, 'amount_confirmation' => $retyped])
        ->assertHasActionErrors(['amount_confirmation' => $retyped === null ? 'required' : 'The two amounts differ. Check the yearly amount before Stripe sends the invoice.']);

    expect(subscriptionRequests($this->stripe))->toBe(0);
})->with([
    'an extra zero' => [20000],
    'left empty' => [null],
]);

it('refuses a customer with a Stripe balance', function (): void {
    $workspace = managedPlanWorkspace();
    $workspace->forceFill(['stripe_id' => 'cus_'.Str::ulid()])->save();
    $this->stripe->respondTo('/v1/customers', ['id' => $workspace->stripe_id, 'object' => 'customer', 'email' => null, 'balance' => -5000]);

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('refuses a plan with no Stripe product configured', function (): void {
    config()->set('services.stripe.products.enterprise', null);

    startManagedPlanFor(managedPlanWorkspace(), ['plan' => Plan::Enterprise->value, 'amount' => 20000])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('refuses a workspace whose owner is gone', function (): void {
    $workspace = managedPlanWorkspace();
    $workspace->owner()->delete();

    startManagedPlanFor($workspace->refresh(), ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('names the Free plan as Free when refusing it', function (): void {
    $administrator = SystemAdministrator::factory()->create();

    expect(fn () => resolve(StartManagedPlan::class)->execute($administrator, managedPlanWorkspace(), Plan::Free, 708))
        ->toThrow(ValidationException::class, 'Free is not a managed plan.');
});

it('refuses a plan that is not managed when the action is called directly', function (): void {
    $administrator = SystemAdministrator::factory()->create();

    expect(fn () => resolve(StartManagedPlan::class)->execute($administrator, managedPlanWorkspace(), Plan::Pro, 708))
        ->toThrow(ValidationException::class);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('refuses a yearly amount below one dollar when the action is called directly', function (int $amount): void {
    $administrator = SystemAdministrator::factory()->create();

    expect(fn () => resolve(StartManagedPlan::class)->execute($administrator, managedPlanWorkspace(), Plan::Concierge, $amount))
        ->toThrow(ValidationException::class);

    expect(subscriptionRequests($this->stripe))->toBe(0);
})->with([0, -5]);

it('refuses while a pending managed invoice is still open, whatever its Stripe status', function (string $status): void {
    $workspace = managedPlanWorkspace();
    Subscription::factory()->create(['workspace_id' => $workspace->getKey(), 'type' => SubscriptionType::ManagedPending->value, 'stripe_status' => $status]);

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasActionErrors(['plan']);

    expect(subscriptionRequests($this->stripe))->toBe(0);
})->with(['active', 'past_due', 'incomplete']);

it('starts again after the pending managed invoice was withdrawn', function (string $status): void {
    $workspace = managedPlanWorkspace();
    Subscription::factory()->create(['workspace_id' => $workspace->getKey(), 'type' => SubscriptionType::ManagedPending->value, 'stripe_status' => $status]);

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    expect(subscriptionRequests($this->stripe))->toBe(1);
})->with(['canceled', 'incomplete_expired']);

it('hides the action from a sysadmin who cannot manage customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    $workspace = managedPlanWorkspace();

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('startManagedPlan')->table($workspace));

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertActionHidden('startManagedPlan');
});

it('forbids a sysadmin without customer access from starting a plan directly', function (): void {
    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);

    expect(fn () => resolve(StartManagedPlan::class)->execute($administrator, managedPlanWorkspace(), Plan::Concierge, 708))
        ->toThrow(HttpException::class);

    expect(subscriptionRequests($this->stripe))->toBe(0);
});

it('shows the action on the workspace page', function (): void {
    $workspace = managedPlanWorkspace();

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertActionVisible('startManagedPlan');
});

function unpaidManagedSubscription(Workspace $workspace, string $status = 'incomplete'): Subscription
{
    return Subscription::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'type' => SubscriptionType::ManagedPending->value,
        'stripe_id' => 'sub_managed_test',
        'stripe_status' => $status,
    ]);
}

function withLatestInvoiceStatus(StripeRecorder $stripe, string $status): void
{
    $stripe->respondTo('/v1/subscriptions', [
        ...managedSubscriptionBody(),
        'latest_invoice' => ['id' => 'in_managed_test', 'object' => 'invoice', 'status' => $status],
    ]);
}

function withdrawManagedInvoiceFor(Workspace $workspace): Testable
{
    return livewire(ListWorkspaces::class)
        ->callAction(TestAction::make('withdrawManagedInvoice')->table($workspace));
}

it('voids the unpaid invoice and cancels its subscription', function (): void {
    $workspace = managedPlanWorkspace();
    $subscription = unpaidManagedSubscription($workspace);
    withLatestInvoiceStatus($this->stripe, 'open');
    $this->stripe->respondTo('POST /v1/invoices/in_managed_test/void', ['id' => 'in_managed_test', 'object' => 'invoice', 'status' => 'void']);

    withdrawManagedInvoiceFor($workspace)->assertHasNoActionErrors()->assertNotified('Invoice withdrawn');

    expect($this->stripe->countFor('POST /v1/invoices/in_managed_test/void'))->toBe(1)
        ->and($this->stripe->countFor('DELETE /v1/subscriptions/sub_managed_test'))->toBe(1)
        ->and($this->stripe->paramsFor('DELETE /v1/subscriptions/sub_managed_test')['prorate'])->toBe('false')
        ->and($subscription->refresh()->stripe_status)->toBe('canceled')
        ->and($subscription->ends_at)->not->toBeNull()
        ->and($workspace->refresh()->plan)->toBe(Plan::Free);
});

it('withdraws the invoice of a workspace that also holds an ended Pro subscription', function (): void {
    $workspace = managedPlanWorkspace();
    Subscription::factory()->canceled()->create(['workspace_id' => $workspace->getKey(), 'stripe_id' => 'sub_ended_pro']);
    unpaidManagedSubscription($workspace, 'active');
    withLatestInvoiceStatus($this->stripe, 'open');
    $this->stripe->respondTo('POST /v1/invoices/in_managed_test/void', ['id' => 'in_managed_test', 'object' => 'invoice', 'status' => 'void']);

    withdrawManagedInvoiceFor($workspace)->assertNotified('Invoice withdrawn');

    expect($this->stripe->countFor('DELETE /v1/subscriptions/sub_managed_test'))->toBe(1);
});

it('withdraws an incomplete pending invoice', function (): void {
    $workspace = managedPlanWorkspace();
    unpaidManagedSubscription($workspace, 'incomplete');
    withLatestInvoiceStatus($this->stripe, 'open');

    withdrawManagedInvoiceFor($workspace)->assertNotified('Invoice withdrawn');

    expect($this->stripe->countFor('POST /v1/invoices/in_managed_test/void'))->toBe(1)
        ->and($workspace->subscription(SubscriptionType::ManagedPending->value)?->stripe_status)->toBe('canceled');
});

it('refuses to withdraw an invoice that is already paid', function (): void {
    $workspace = managedPlanWorkspace();
    $subscription = unpaidManagedSubscription($workspace, 'active');
    withLatestInvoiceStatus($this->stripe, 'paid');

    withdrawManagedInvoiceFor($workspace)->assertNotified(
        Notification::make()
            ->danger()
            ->title('Invoice not withdrawn')
            ->body('The invoice is already paid. The plan switches on when Stripe confirms the payment.'),
    );

    expect($this->stripe->countFor('POST /v1/invoices/in_managed_test/void'))->toBe(0)
        ->and($this->stripe->countFor('DELETE /v1/subscriptions/sub_managed_test'))->toBe(0)
        ->and($subscription->refresh()->stripe_status)->toBe('active');
});

it('cancels without voiding again when a retry finds the invoice already void', function (): void {
    $workspace = managedPlanWorkspace();
    unpaidManagedSubscription($workspace);
    withLatestInvoiceStatus($this->stripe, 'void');

    withdrawManagedInvoiceFor($workspace)->assertNotified('Invoice withdrawn');

    expect($this->stripe->countFor('POST /v1/invoices/in_managed_test/void'))->toBe(0)
        ->and($this->stripe->countFor('DELETE /v1/subscriptions/sub_managed_test'))->toBe(1);
});

it('finalizes then voids a draft invoice before cancelling so Stripe never collects it', function (): void {
    $workspace = managedPlanWorkspace();
    unpaidManagedSubscription($workspace);
    withLatestInvoiceStatus($this->stripe, 'draft');
    $offset = count($this->stripe->requests);

    withdrawManagedInvoiceFor($workspace)->assertNotified('Invoice withdrawn');

    expect($this->stripe->writesSince($offset))->toBe([
        ['method' => 'POST', 'url' => 'https://api.stripe.com/v1/invoices/in_managed_test/finalize'],
        ['method' => 'POST', 'url' => 'https://api.stripe.com/v1/invoices/in_managed_test/void'],
        ['method' => 'DELETE', 'url' => 'https://api.stripe.com/v1/subscriptions/sub_managed_test'],
    ])
        ->and($this->stripe->paramsFor('POST /v1/invoices/in_managed_test/finalize')['auto_advance'])->toBe('false')
        ->and($this->stripe->paramsFor('DELETE /v1/subscriptions/sub_managed_test')['prorate'])->toBe('false');
});

it('tells the sysadmin to reload when Stripe rejects the void', function (): void {
    $workspace = managedPlanWorkspace();
    $subscription = unpaidManagedSubscription($workspace);
    withLatestInvoiceStatus($this->stripe, 'open');
    $this->stripe->respondTo('POST /v1/invoices/in_managed_test/void', ['error' => ['type' => 'invalid_request_error', 'message' => 'You can only void open invoices.']], 400);

    Exceptions::fake();

    withdrawManagedInvoiceFor($workspace)->assertNotified(
        Notification::make()
            ->danger()
            ->title('Invoice not withdrawn')
            ->body('Stripe did not complete the request. Reload the page: the invoice may have been paid or the request may need another try.'),
    );

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), 'sub_managed_test')
        && str_contains($exception->getMessage(), (string) $workspace->getKey())
        && $exception->getPrevious() instanceof ApiErrorException);

    expect($this->stripe->countFor('DELETE /v1/subscriptions/sub_managed_test'))->toBe(0)
        ->and($subscription->refresh()->stripe_status)->toBe('incomplete');
});

it('hides withdraw when no managed invoice is waiting', function (?string $status): void {
    $workspace = managedPlanWorkspace();

    if ($status !== null) {
        unpaidManagedSubscription($workspace, $status);
    }

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('withdrawManagedInvoice')->table($workspace));

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertActionHidden('withdrawManagedInvoice');
})->with([null, 'canceled', 'incomplete_expired']);

it('shows withdraw while a managed invoice is waiting', function (): void {
    $workspace = managedPlanWorkspace();
    unpaidManagedSubscription($workspace);

    livewire(ListWorkspaces::class)
        ->assertActionVisible(TestAction::make('withdrawManagedInvoice')->table($workspace));

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->assertActionVisible('withdrawManagedInvoice');
});

function subscriptionQueriesWhileListing(int $workspaces): int
{
    Workspace::query()->delete();

    foreach (Workspace::factory()->count($workspaces)->create() as $workspace) {
        unpaidManagedSubscription($workspace)->forceFill(['stripe_id' => 'sub_listing_'.Str::lower(Str::random(8))])->save();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();

    livewire(ListWorkspaces::class)->assertSuccessful();

    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], '"subscriptions"'))->count();

    DB::disableQueryLog();

    return $queries;
}

it('reads the withdraw visibility from the loaded subscriptions instead of one query per workspace', function (): void {
    $fewWorkspaces = subscriptionQueriesWhileListing(2);
    $manyWorkspaces = subscriptionQueriesWhileListing(6);

    expect($manyWorkspaces)->toBe($fewWorkspaces);
});

it('offers withdraw on the workspace page right after the invoice is sent', function (): void {
    $workspace = managedPlanWorkspace();

    livewire(ViewWorkspace::class, ['record' => $workspace->getRouteKey()])
        ->callAction('startManagedPlan', ['plan' => Plan::Concierge->value, 'amount' => 708, 'amount_confirmation' => 708])
        ->assertHasNoActionErrors()
        ->assertActionVisible('withdrawManagedInvoice');
});

it('lets the sysadmin start again after withdrawing', function (): void {
    $workspace = managedPlanWorkspace();
    unpaidManagedSubscription($workspace);
    withLatestInvoiceStatus($this->stripe, 'open');

    startManagedPlanFor($workspace, ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasActionErrors(['plan']);

    withdrawManagedInvoiceFor($workspace)->assertNotified('Invoice withdrawn');

    startManagedPlanFor($workspace->refresh(), ['plan' => Plan::Concierge->value, 'amount' => 708])
        ->assertHasNoActionErrors();

    expect(subscriptionRequests($this->stripe))->toBe(1);
});

it('refuses to withdraw when no managed invoice is waiting', function (): void {
    $administrator = SystemAdministrator::factory()->create();

    expect(fn () => resolve(WithdrawManagedInvoice::class)->execute($administrator, managedPlanWorkspace()))
        ->toThrow(ValidationException::class);

    expect($this->stripe->requests)->toBeEmpty();
});

it('forbids a sysadmin without customer access from withdrawing directly', function (): void {
    $administrator = SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]);
    $workspace = managedPlanWorkspace();
    unpaidManagedSubscription($workspace);

    expect(fn () => resolve(WithdrawManagedInvoice::class)->execute($administrator, $workspace))
        ->toThrow(HttpException::class);

    expect($this->stripe->requests)->toBeEmpty();
});

it('hides withdraw from a sysadmin who cannot manage customer access', function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(['role' => SystemAdministratorRole::Administrator]), 'sysadmin');
    $workspace = managedPlanWorkspace();
    unpaidManagedSubscription($workspace);

    livewire(ListWorkspaces::class)
        ->assertActionHidden(TestAction::make('withdrawManagedInvoice')->table($workspace));
});
