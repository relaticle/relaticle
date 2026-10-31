<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Relaticle\Cloud\Billing\Jobs\RefreshManagedRenewal;
use Relaticle\Cloud\Billing\Mail\ProEndedMail;
use Relaticle\Cloud\Billing\Mail\ProTrialEndingSoonMail;
use Relaticle\Cloud\Billing\Notifications\ManagedInvoiceOverdueNotification;
use Relaticle\Cloud\Billing\Notifications\PaymentFailedNotification;
use Relaticle\Cloud\CloudServiceProvider;

mutates(CloudServiceProvider::class, PaymentFailedNotification::class, ManagedInvoiceOverdueNotification::class, ProEndedMail::class, ProTrialEndingSoonMail::class, RefreshManagedRenewal::class);

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
        fn (): string => __('cloud::billing.payment_failed.notification_title', ['workspace' => 'Aging Workspace']),
    ],
    'managed invoice overdue' => [
        fn (Workspace $workspace): ManagedInvoiceOverdueNotification => new ManagedInvoiceOverdueNotification($workspace, Plan::Concierge),
        'App\Notifications\ManagedInvoiceOverdueNotification',
        fn (): string => __('cloud::billing.managed.overdue_title', ['plan' => Plan::Concierge->marketingLabel()]),
    ],
]);

it('loads a payload serialized under the class name it had before the move', function (string $preMoveName, string $current): void {
    $payload = 'O:'.strlen($preMoveName).':"'.$preMoveName.'":0:{}';

    expect(unserialize($payload))->toBeInstanceOf($current);
})->with([
    'payment failed notification' => ['App\Notifications\PaymentFailedNotification', PaymentFailedNotification::class],
    'managed invoice overdue notification' => ['App\Notifications\ManagedInvoiceOverdueNotification', ManagedInvoiceOverdueNotification::class],
    'pro ended mail' => ['App\Mail\ProEndedMail', ProEndedMail::class],
    'pro trial ending soon mail' => ['App\Mail\ProTrialEndingSoonMail', ProTrialEndingSoonMail::class],
    'managed renewal refresh job' => ['App\Jobs\RefreshManagedRenewal', RefreshManagedRenewal::class],
]);
