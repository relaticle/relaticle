<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Actions;

use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Stripe\Invoice;

final readonly class WithdrawManagedInvoice
{
    private const array VOIDABLE_STATUSES = [Invoice::STATUS_DRAFT, Invoice::STATUS_OPEN, Invoice::STATUS_UNCOLLECTIBLE];

    public function execute(SystemAdministrator $admin, Workspace $workspace): void
    {
        abort_unless(Gate::forUser($admin)->allows('manageManagedPlan', $workspace), 403);

        $subscription = $workspace->pendingManagedSubscription();

        if (! $subscription instanceof Subscription) {
            $this->refuse('No managed invoice is waiting to be paid.');
        }

        $invoice = $subscription->asStripeSubscription(['latest_invoice'])->latest_invoice;

        if ($invoice?->status === Invoice::STATUS_PAID) {
            $this->refuse('The invoice is already paid. The plan switches on when Stripe confirms the payment.');
        }

        if ($invoice?->status === Invoice::STATUS_DRAFT) {
            Cashier::stripe()->invoices->finalizeInvoice($invoice->id, ['auto_advance' => false]);
        }

        if ($invoice !== null && in_array($invoice->status, self::VOIDABLE_STATUSES, true)) {
            Cashier::stripe()->invoices->voidInvoice($invoice->id);
        }

        $subscription->noProrate()->cancelNow();
    }

    private function refuse(string $message): never
    {
        throw ValidationException::withMessages(['invoice' => $message]);
    }
}
