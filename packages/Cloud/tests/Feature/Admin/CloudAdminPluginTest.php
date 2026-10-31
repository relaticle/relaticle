<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\Cloud\Admin\CloudAdminPlugin;
use Relaticle\Cloud\Admin\Filament\Pages\Overview;
use Relaticle\Cloud\Admin\Filament\RelationManagers\SubscriptionsRelationManager;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditBalanceResource;
use Relaticle\Cloud\Admin\Filament\Resources\AiCreditTransactions\AiCreditTransactionResource;
use Relaticle\Cloud\Admin\Filament\Resources\SubscriptionResource;
use Relaticle\SystemAdmin\Filament\Resources\WorkspaceResource;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(CloudAdminPlugin::class, Overview::class, SubscriptionsRelationManager::class, SubscriptionResource::class, AiCreditBalanceResource::class, AiCreditTransactionResource::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
});

it('lands a system administrator on the business overview when cloud is loaded', function (): void {
    $this->get('/sysadmin')
        ->assertOk()
        ->assertSeeLivewire(Overview::class);
});

it('renders the subscriptions relation manager on the workspace page', function (): void {
    $workspace = User::factory()->withWorkspace()->create()->currentWorkspace;
    $tab = array_search(SubscriptionsRelationManager::class, WorkspaceResource::getRelations(), true);

    $this->get(WorkspaceResource::getUrl('view', ['record' => $workspace, 'relation' => $tab]))
        ->assertOk()
        ->assertSeeLivewire(SubscriptionsRelationManager::class);
});

it('answers the cloud resources from the sysadmin policies', function (): void {
    expect(SubscriptionResource::canViewAny())->toBeTrue()
        ->and(AiCreditBalanceResource::canViewAny())->toBeTrue()
        ->and(AiCreditTransactionResource::canViewAny())->toBeTrue()
        ->and(SubscriptionResource::canCreate())->toBeFalse()
        ->and(AiCreditBalanceResource::canCreate())->toBeFalse()
        ->and(AiCreditTransactionResource::canCreate())->toBeFalse();
});
