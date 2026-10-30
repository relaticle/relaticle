<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Relaticle\Cloud\Billing\Notifications\ManagedInvoiceOverdueNotification;
use Relaticle\Cloud\Billing\Notifications\PaymentFailedNotification;
use Relaticle\Cloud\CloudServiceProvider;

mutates(CloudServiceProvider::class, PaymentFailedNotification::class, ManagedInvoiceOverdueNotification::class);

it('still shows a bell row stored under the class name it had before the move', function (Closure $notification, string $storedType, Closure $title): void {
    $owner = User::factory()->withPersonalWorkspace()->create();
    $workspace = Workspace::factory()->create(['user_id' => $owner->getKey(), 'personal_workspace' => false, 'name' => 'Aging Workspace']);

    $owner->notify($notification($workspace));
    DB::table('notifications')->update(['type' => $storedType]);

    Livewire::actingAs($owner)->test(AppDatabaseNotifications::class)->assertSee($title());
})->with([
    'payment failed' => [
        fn (Workspace $workspace): PaymentFailedNotification => new PaymentFailedNotification($workspace),
        'App\Notifications\PaymentFailedNotification',
        fn (): string => __('billing.payment_failed.notification_title', ['workspace' => 'Aging Workspace']),
    ],
    'managed invoice overdue' => [
        fn (Workspace $workspace): ManagedInvoiceOverdueNotification => new ManagedInvoiceOverdueNotification($workspace, Plan::Concierge),
        'App\Notifications\ManagedInvoiceOverdueNotification',
        fn (): string => __('billing.managed.overdue_title', ['plan' => Plan::Concierge->marketingLabel()]),
    ],
]);
