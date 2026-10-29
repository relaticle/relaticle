<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use Laravel\Cashier\Cashier;
use Laravel\Cashier\Subscription;
use Stripe\Invoice;

// Stripe keeps a cancelled subscription's open invoices payable, so a payment would buy nothing.
final readonly class VoidOpenInvoices
{
    public function execute(Subscription $subscription): void
    {
        $invoices = Cashier::stripe()->invoices->all(['subscription' => $subscription->stripe_id, 'status' => 'open', 'limit' => 100]);

        foreach ($invoices->data as $invoice) {
            if ($invoice->status === Invoice::STATUS_OPEN) {
                Cashier::stripe()->invoices->voidInvoice($invoice->id);
            }
        }
    }
}
